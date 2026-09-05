<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Pushery\LegalConsent\Contracts\LegalTextTranslator;
use Pushery\LegalConsent\Enums\BlockingReason;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Exceptions\LeadTimeTooShortException;
use Pushery\LegalConsent\Exceptions\LegalReleaseNotReady;
use Pushery\LegalConsent\Exceptions\NoticeTimelineInvertedException;
use Pushery\LegalConsent\Exceptions\TranslatorNotConfigured;
use Pushery\LegalConsent\Livewire\Concerns\AnnouncesStatus;
use Pushery\LegalConsent\Livewire\Concerns\AuthorizesLegalAdmin;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\LegalDraft;
use Pushery\LegalConsent\Support\LegalDocumentReleaser;
use Pushery\LegalConsent\Support\LegalDraftSet;
use Pushery\LegalConsent\Support\LegalDraftWriter;
use Pushery\LegalConsent\Support\ReleaseOptions;

/**
 * Edits ONE draft — one document key, one locale.
 *
 * The editor never writes bytes itself: every Save/Translate goes through {@see LegalDraftWriter},
 * which owns the invariant that a draft body is always sanitized pipeline output. That is what
 * keeps this screen — the app's first `{!! !!}` sink — from becoming a stored-XSS hole: the text
 * is sanitized on the way in, by the same allowlist the ledger hashes.
 *
 * Fail-closed behind {@see AuthorizesLegalAdmin} (404 with no ability configured).
 */
final class LegalTextEditor extends Component
{
    use AnnouncesStatus;
    use AuthorizesLegalAdmin;

    /**
     * The draft identity this editor was opened on — one document key, one locale.
     *
     * Both are `#[Locked]`. They are set once at mount and never derived again, and together they
     * address the row that save(), translate() and markReviewed() write to. Left writable, the
     * browser picks that address at click time: markReviewed() is the human sign-off on THESE
     * bytes and the only route to a publishable draft, so a sign-off that can be redirected to a
     * draft the screen never rendered is not a sign-off. No view binds either of them — only
     * $body is bound.
     */
    #[Locked]
    public string $key = '';

    /** Locked for the reason above: it is half of the row identity, not an input. */
    #[Locked]
    public string $locale = '';

    /**
     * The objection window a deemed-consent release binds on — announce, deadline, enforce.
     *
     * ⚠️ THIS SURFACE IS HERE RATHER THAN ON THE MANAGER GRID, and that placement is the finding
     * rather than a preference. `LegalTextManager` says in its own words that the deemed and
     * info-only modes belong to "the editor controls or the CLI, not a button on an overview
     * grid" — a deemed release binds people by their SILENCE (§ 308 Nr. 5 BGB), and a one-click
     * grid action is the wrong amount of friction for that.
     *
     * Measured 2026-09-05: of the two routes that sentence names, only the CLI existed. The
     * editor carried nothing — zero references to a notice mode, an objection deadline or
     * ReleaseOptions. So an application with an admin UI had no in-app path to a capability this
     * package implements end to end, and a consuming app rebuilt the screen itself. The prose
     * described a surface that was not there.
     *
     * Empty strings rather than nulls because they are bound to date inputs, which submit "".
     */
    public string $announceAt = '';

    /** The date an objection must arrive by. See {@see $announceAt}. */
    public string $objectionDeadline = '';

    /** The date the change takes effect. See {@see $announceAt}. */
    public string $enforceAt = '';

    /** Whether the change grants a free right to terminate (§ 675g Abs. 2, P2B Art. 3). */
    public bool $offersTermination = false;

    /** Whether the unmodified version stays on offer to whoever objects. */
    public bool $keepsUnmodified = false;

    /** The edited bytes. Client-writable by design — {@see LegalDraftWriter} sanitizes on the way in. */
    public string $body = '';

    /**
     * The document key arrives as `documentKey`, never `key`: Livewire reserves `key` for its own
     * DOM-diffing identity and strips it before mount(), so `<livewire:… :key="'terms'" />` — the
     * form the docs used to show — could never reach this method. Mount it as
     * `<livewire:legal-consent.legal-text-editor :document-key="'terms'" :locale="'de'" />`.
     * The internal property stays `$key`; only the mount parameter had to move.
     */
    public function mount(string $documentKey, string $locale): void
    {
        $this->key = $documentKey;
        $this->locale = $locale;

        $draft = LegalDraftSet::for($documentKey)->draft($locale);
        $this->body = $draft instanceof LegalDraft ? $draft->body : '';
    }

    public function save(): void
    {
        app(LegalDraftWriter::class)->save($this->key, $this->locale, $this->body, $this->actor());

        // A status message after a save, and after the two acts below — WCAG 4.1.3: an action that
        // changes the record must announce its result, not leave a screen reader in silence.
        $this->setStatus((string) __('legal-consent::ui.admin_status_saved'));
    }

    public function translate(): void
    {
        $sourceLocale = $this->sourceLocale();

        if ($this->locale === $sourceLocale) {
            $this->setStatus((string) __('legal-consent::ui.admin_status_source_not_translated'));

            return;
        }

        $source = LegalDraftSet::for($this->key)->draft($sourceLocale);

        if (! $source instanceof LegalDraft) {
            $this->setStatus((string) __('legal-consent::ui.admin_status_no_source'));

            return;
        }

        try {
            $translated = app(LegalTextTranslator::class)->translate($source->body, $sourceLocale, $this->locale);
        } catch (TranslatorNotConfigured $e) {
            $this->setStatus($e->getMessage());

            return;
        }

        $draft = app(LegalDraftWriter::class)->applyTranslation($this->key, $this->locale, $translated, $source->content_hash, $this->actor());
        $this->body = $draft->body;
        $this->setStatus((string) __('legal-consent::ui.admin_status_machine_translated'));
    }

    public function markReviewed(): void
    {
        app(LegalDraftWriter::class)->markReviewed($this->key, $this->locale, $this->actor());
        $this->setStatus((string) __('legal-consent::ui.admin_status_reviewed'));
    }

    /**
     * Release this document across its locales as a DEEMED-CONSENT change, on a stated objection
     * window.
     *
     * Every failure here is a status message, never a fatal. Three can happen and they mean
     * different things to the person clicking:
     *
     *  - the set is not ready (a locale unwritten or unreviewed) — {@see LegalReleaseNotReady}
     *  - the window runs backwards — {@see NoticeTimelineInvertedException}
     *  - the window is shorter than the statutory lead time — {@see LeadTimeTooShortException}
     *
     * The last two are the ones that make this surface worth shipping rather than leaving to the
     * CLI: an operator picking dates in a form finds out immediately, in their own language, that
     * a window is too short to bind. A `php artisan` invocation tells them the same thing in a
     * stack trace, on a screen the person deciding is usually not looking at.
     */
    public function releaseDeemed(): void
    {
        try {
            $released = app(LegalDocumentReleaser::class)->release(
                $this->key,
                NoticeMode::DeemedConsent,
                $this->locales(),
                new ReleaseOptions(
                    announceAt: $this->date($this->announceAt),
                    enforceAt: $this->date($this->enforceAt),
                    objectionDeadline: $this->date($this->objectionDeadline),
                    offersTermination: $this->offersTermination,
                    keepsUnmodified: $this->keepsUnmodified,
                ),
            );
        } catch (LegalReleaseNotReady $e) {
            $this->setStatus((string) __('legal-consent::ui.admin_status_release_blocked', [
                'key' => $this->key,
                'reasons' => implode('; ', array_map(
                    static fn (string $locale, BlockingReason $reason): string => "{$locale} (".__($reason->label()).')',
                    array_keys($e->blocking),
                    array_values($e->blocking),
                )),
            ]));

            return;
        } catch (NoticeTimelineInvertedException|LeadTimeTooShortException $e) {
            // The message carries the package's own numbers (which minimum, which dates), so it is
            // shown rather than replaced by a vaguer sentence of our own.
            $this->setStatus((string) __('legal-consent::ui.admin_status_deemed_window_rejected', [
                'reason' => $e->getMessage(),
            ]));

            return;
        }

        $first = $released->first();
        $affects = $first instanceof LegalDocument ? app(LegalDocumentReleaser::class)->affects($first) : 0;
        $this->setStatus((string) __('legal-consent::ui.admin_status_released', [
            'key' => $this->key,
            'count' => count($released),
            'affects' => $affects,
        ]));
    }

    public function render(): View
    {
        $set = LegalDraftSet::for($this->key);
        $draft = $set->draft($this->locale);

        return view('legal-consent::livewire.legal-text-editor', [
            'sourceLocale' => $this->sourceLocale(),
            'isSource' => $this->locale === $this->sourceLocale(),
            'reviewState' => $draft?->review_state->value,
            'stale' => $draft instanceof LegalDraft && $set->isStale($draft),
            // The preview renders exactly what a publish would freeze — the already-sanitized body,
            // not a re-render — so it is a true fixpoint of what the subject will see.
            'preview' => $draft instanceof LegalDraft ? $draft->body : '',
        ]);
    }

    /**
     * A date input's value as a Carbon, or null when it was left empty.
     *
     * Null rather than "today": an omitted date means the operator did not state one, and the
     * publisher's own defaults are the right answer to that. Substituting now() here would invent
     * a window nobody chose and freeze it into a proof row.
     */
    private function date(string $value): ?CarbonImmutable
    {
        return $value === '' ? null : CarbonImmutable::parse($value);
    }

    /**
     * The locales a release covers — the same `legal-consent.locales` the manager reads, so the
     * two admin surfaces cannot disagree about which set a release touches.
     *
     * @return list<string>
     */
    private function locales(): array
    {
        $locales = config('legal-consent.locales');

        return is_array($locales) ? array_values(array_filter($locales, is_string(...))) : [];
    }

    private function sourceLocale(): string
    {
        $locale = config('legal-consent.default_locale');

        return is_string($locale) ? $locale : 'de';
    }

    /** The acting user's identifier, handed to the writer's events for the consumer's audit log. */
    private function actor(): ?string
    {
        $id = auth()->user()?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? (string) $id : null;
    }
}
