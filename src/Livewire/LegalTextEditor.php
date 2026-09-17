<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Pushery\LegalConsent\Contracts\LegalTextTranslator;
use Pushery\LegalConsent\Contracts\NamesLegalTexts;
use Pushery\LegalConsent\Enums\BlockingReason;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Exceptions\LeadTimeTooShortException;
use Pushery\LegalConsent\Exceptions\LegalDocumentTooLarge;
use Pushery\LegalConsent\Exceptions\LegalDocumentUnparsable;
use Pushery\LegalConsent\Exceptions\LegalPublishRefused;
use Pushery\LegalConsent\Exceptions\LegalReleaseNotReady;
use Pushery\LegalConsent\Exceptions\NoticeTimelineInvertedException;
use Pushery\LegalConsent\Exceptions\TranslatorNotConfigured;
use Pushery\LegalConsent\Jobs\TranslateLegalDraft;
use Pushery\LegalConsent\Livewire\Concerns\AnnouncesStatus;
use Pushery\LegalConsent\Livewire\Concerns\AuthorizesLegalAdmin;
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
     * THIS SURFACE IS HERE RATHER THAN ON THE MANAGER GRID, and that placement is the finding
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
     * Bumped whenever the SERVER replaces the body, so the view can key the rich editor off it.
     *
     * The WireKit editor lives behind `wire:ignore`, which is WireKit's documented integration and
     * keeps Livewire from morphing a mounted ProseMirror node. It also keeps a server-side
     * replacement out: after a translation the visible editor still showed the old text while the
     * hidden field already carried the new one, so the reader edited what they saw, the engine
     * wrote its own document back on the next keystroke, and a save stored the old text over the
     * translation. Nothing failed anywhere, which is why this is data loss rather than a display
     * fault. Measured by a consumer against v0.34.0 in Chromium.
     *
     * NOT `md5($body)`, which is the obvious derivation and fires too often. The client's own
     * typing reaches the server with the next action, so a plain save would change that key too and
     * rebuild the editor from scratch — on a privacy notice of some fifteen kilobytes that throws
     * away the cursor and the scroll position of the text somebody is proofreading, every time they
     * save. The question the key has to answer is not "did the text change" but "did it change
     * WITHOUT the client doing it", and only the server can know that.
     *
     * The same shape as `$statusNonce`, and LOCKED where that one is not. The sibling is unlocked
     * because a client bumping it only re-announces a sentence the server already wrote; this one
     * decides whether the mounted editor is torn down and rebuilt, so a client that bumps it throws
     * away its own cursor and whatever the engine holds that the property does not. Nothing needs to
     * write it from the browser, and the stricter classification costs nothing.
     */
    #[Locked]
    public int $bodyNonce = 0;

    /**
     * Whether this page dispatched a translation whose result it has not taken yet.
     *
     * Locked, because it is not an input: a client that set it would have the next render replace
     * its unsaved edits with the stored draft.
     */
    #[Locked]
    public bool $awaitingTranslation = false;

    /**
     * The document key arrives as `documentKey`, never `key`: Livewire reserves `key` for its own
     * DOM-diffing identity and strips it before mount(), so `<livewire:… :key="'terms'" />` — the
     * form the docs used to show — could never reach this method. Mount it as
     * `<livewire:legal-consent.legal-text-editor :document-key="'terms'" :locale="'de'" />`.
     * The internal property stays `$key`; only the mount parameter had to move.
     */
    public function mount(string $documentKey, string $locale): void
    {
        // The same refusal the manager makes at the top of releaseAll(), and for the same reason.
        // These are mount parameters rather than action arguments, so Livewire does not rewrite
        // them between requests — but the documentation says to mount this component inside your
        // own admin routing, and a consumer filling them from a route parameter has made them
        // client input without being told they had.
        //
        // Unchecked, an unknown key reaches the source factory, which has nothing to resolve for
        // it and raises: a 500 on an admin screen for a request that is simply not a thing.
        abort_unless(in_array($documentKey, $this->documentKeys(), true), 404);

        // AND THE LOCALE, WHICH IS THE ONE THAT FAILS QUIETLY. releaseDeemed() releases the
        // locales from the configuration, never the one this screen is editing. Measured: an
        // editor mounted on `fr` with `locales => ['de']` accepts the text, reports it reviewed,
        // and then publishes `de` — while telling the operator that »terms« was released. The
        // text the person just wrote and released is not published, and nothing says so.
        abort_unless(in_array($locale, $this->locales(), true), 404);

        $this->key = $documentKey;
        $this->locale = $locale;

        $draft = LegalDraftSet::for($documentKey)->draft($locale);
        $this->replaceBody($draft instanceof LegalDraft ? $draft->body : '');
    }

    public function save(): void
    {
        try {
            app(LegalDraftWriter::class)->save($this->key, $this->locale, $this->body, $this->actor());
        } catch (LegalDocumentTooLarge|LegalDocumentUnparsable $e) {
            // Both refusals come from the render pipeline and both are the operator's input, so
            // they belong on the screen rather than in a 500. The unparsable one is the sharper
            // case: it means the HTML parser stopped part-way, and the alternative to refusing is
            // freezing a hash over the fragment.
            $this->setStatus(__('legal-consent::ui.admin_status_not_saved', ['reason' => $e->getMessage()]));

            return;
        }

        // A status message after a save, and after the two acts below — WCAG 4.1.3: an action that
        // changes the record must announce its result, not leave a screen reader in silence.
        $this->setStatus(__('legal-consent::ui.admin_status_saved'));
    }

    public function translate(): void
    {
        $sourceLocale = $this->sourceLocale();

        if ($this->locale === $sourceLocale) {
            $this->setStatus(__('legal-consent::ui.admin_status_source_not_translated'));

            return;
        }

        $source = LegalDraftSet::for($this->key)->draft($sourceLocale);

        if (! $source instanceof LegalDraft) {
            $this->setStatus(__('legal-consent::ui.admin_status_no_source'));

            return;
        }

        // OFF THE REQUEST, where an application asked for that. The translator is the application's
        // own binding and might answer in microseconds or in minutes; a consumer measured the inline
        // call ending in a 500 twice in one day on a privacy notice. Dispatching answers that without
        // this package guessing a timeout on somebody else's service.
        //
        // The marker is written HERE rather than in the job, and that ordering is the point: between
        // dispatch and the worker picking the job up there is a window, and a screen that polled
        // through it would see no marker and conclude the translation had already finished.
        if ($this->queuesTranslation()) {
            Cache::put(TranslateLegalDraft::markerFor($this->key, $this->locale), true, now()->addHour());

            TranslateLegalDraft::dispatch($this->key, $this->locale, $sourceLocale, $this->actor());

            $this->awaitingTranslation = true;
            $this->setStatus(__('legal-consent::ui.admin_status_translation_queued'));

            return;
        }

        try {
            $translated = app(LegalTextTranslator::class)->translate($source->body, $sourceLocale, $this->locale);
        } catch (TranslatorNotConfigured $e) {
            $this->setStatus($e->getMessage());

            return;
        }

        try {
            $draft = app(LegalDraftWriter::class)->applyTranslation($this->key, $this->locale, $translated, $source->content_hash, $this->actor());
        } catch (LegalDocumentTooLarge|LegalDocumentUnparsable $e) {
            // The translator's output travels the same pipeline and is not privileged — a service
            // that returns something the parser gives up on must not freeze a fragment either.
            $this->setStatus(__('legal-consent::ui.admin_status_not_saved', ['reason' => $e->getMessage()]));

            return;
        }

        $this->replaceBody($draft->body);
        $this->setStatus(__('legal-consent::ui.admin_status_machine_translated'));
    }

    /** Whether this application asked for the translation to leave the request. */
    private function queuesTranslation(): bool
    {
        return (bool) config('legal-consent.translation.queue', false);
    }

    /**
     * Whether a translation of THIS draft is running right now.
     *
     * PRIVATE, and the views read it as view DATA rather than calling it. A public method on a
     * Livewire component is a client-callable ACTION, and this package's own trust-boundary check
     * refuses one that announces no result — correctly, because this is a reader rather than an
     * action, and the only caller that needs it is `render()`.
     *
     * It is a hint rather than a lock — see the job — and it answers false when the feature is off,
     * so a screen without a queue never polls.
     */
    private function translating(): bool
    {
        return $this->queuesTranslation()
            && Cache::get(TranslateLegalDraft::markerFor($this->key, $this->locale)) === true;
    }

    /**
     * Take the result of a queued translation once the worker has finished with it.
     *
     * A dispatched job is invisible to the page that dispatched it: it writes the draft and clears
     * the marker, and nothing tells this component. `$body` is a persisted property, so it kept the
     * pre-translation text — on the SERVER, not only in the DOM — and the page went on showing it
     * after polling stopped. A save then wrote that text over the translation, which is the same
     * silent loss the inline path had, on the route this package recommends for a real translator.
     *
     * It replaces unsaved edits, and that is the existing contract rather than a new one: the
     * inline path has always overwritten `$body` with the translation, and the status says which
     * text is now on screen.
     *
     * ANNOUNCED ONLY WHEN THE DRAFT ACTUALLY MOVED. A job that gave up — a translator that threw,
     * a source that vanished — clears the same marker, and claiming a machine translation there
     * would be a report about something that did not happen. What such a run leaves behind is a
     * page still reading "queued" and no way to learn it failed; that needs a durable failure
     * record and is filed rather than guessed at here.
     */
    private function takeQueuedTranslation(?LegalDraft $draft): void
    {
        if (! $this->awaitingTranslation || $this->translating()) {
            return;
        }

        $this->awaitingTranslation = false;

        // A failure is read before the draft, because it is the more specific answer: a run that
        // recorded one left the draft exactly as it was, so the check below would end in silence
        // and the operator would be left to infer the failure from a text that never appeared.
        $failure = TranslateLegalDraft::takeFailure($this->key, $this->locale);

        if ($failure !== null) {
            $this->setStatus($failure !== ''
                ? __('legal-consent::ui.admin_status_not_saved', ['reason' => $failure])
                : __('legal-consent::ui.admin_status_translation_failed'));

            return;
        }

        // NO DRAFT AND NO RECORDED FAILURE means the run is over and left nothing — the marker
        // reached its time-to-live, or a worker was replaced before it wrote one. It is the last
        // path that could end in silence, and silence here reads as the run still being underway,
        // which is exactly the hour this whole mechanism was built to stop.
        if (! $draft instanceof LegalDraft) {
            $this->setStatus(__('legal-consent::ui.admin_status_translation_failed'));

            return;
        }

        // The bytes move only when they differ, because replacing them tears the editor down and
        // rebuilds it. The SENTENCE is not conditional on that: a translator that answered with the
        // text already on screen still finished, and a screen left reading "queued" over a finished
        // run is the same defect one case over.
        if ($draft->body !== $this->body) {
            $this->replaceBody($draft->body);
        }

        $this->setStatus(__('legal-consent::ui.admin_status_machine_translated'));
    }

    /**
     * The one writer of `$body` on the server, so the nonce cannot be forgotten at a new call site.
     *
     * {@see $bodyNonce} for why the view needs to know, and why the answer is not a hash of the
     * text. Livewire's own hydration writes the property directly, which is correct: that is the
     * client's text arriving, and the editor already holds it.
     */
    private function replaceBody(string $body): void
    {
        $this->body = $body;
        $this->bodyNonce++;
    }

    public function markReviewed(): void
    {
        app(LegalDraftWriter::class)->markReviewed($this->key, $this->locale, $this->actor());
        $this->setStatus(__('legal-consent::ui.admin_status_reviewed'));
    }

    /**
     * Release this document across its locales as a DEEMED-CONSENT change, on a stated objection
     * window.
     *
     * Every failure here is a status message, never a fatal. Four kinds can happen and they mean
     * different things to the person clicking:
     *
     *  - the set is not ready (a locale unwritten or unreviewed) — {@see LegalReleaseNotReady}
     *  - the window runs backwards — {@see NoticeTimelineInvertedException}
     *  - the window is shorter than the statutory lead time — {@see LeadTimeTooShortException}
     *  - the publisher refuses the version itself: a major that must gate, a missing objection
     *    deadline, a version lower than the active one — {@see LegalPublishRefused}
     *
     * The last two are the ones that make this surface worth shipping rather than leaving to the
     * CLI: an operator picking dates in a form finds out immediately, in their own language, that
     * a window is too short to bind. A `php artisan` invocation tells them the same thing in a
     * stack trace, on a screen the person deciding is usually not looking at.
     */
    public function releaseDeemed(): void
    {
        $unreadable = $this->unreadableDates();

        if ($unreadable !== []) {
            $this->setStatus(__('legal-consent::ui.admin_status_deemed_window_rejected', [
                'reason' => 'unreadable date in '.implode(', ', $unreadable).' — expected YYYY-MM-DD',
            ]));

            return;
        }

        try {
            $released = app(LegalDocumentReleaser::class)->release(
                $this->key,
                NoticeMode::DeemedConsent,
                // The locales a release of this document covers, which is not always every
                // configured one. This screen used to narrow nothing at all, so an
                // informational page released from HERE was refused over a language that binds
                // nobody — while the same page released from the grid went through.
                LegalDraftSet::for($this->key)->releaseLocales($this->locales()),
                new ReleaseOptions(
                    announceAt: $this->date($this->announceAt),
                    enforceAt: $this->date($this->enforceAt),
                    objectionDeadline: $this->date($this->objectionDeadline),
                    offersTermination: $this->offersTermination,
                    keepsUnmodified: $this->keepsUnmodified,
                ),
            );
        } catch (LegalReleaseNotReady $e) {
            $names = app(NamesLegalTexts::class);

            $this->setStatus(__('legal-consent::ui.admin_status_release_blocked', [
                'key' => $names->document($this->key),
                'reasons' => implode('; ', array_map(
                    // Named rather than keyed, on both halves of the sentence: the reasons have been
                    // translated since 0.22.0, and an administrator read `terms (fr)` beside them.
                    static fn (string $locale, BlockingReason $reason): string => $names->language($locale).' ('.__($reason->label()).')',
                    array_keys($e->blocking),
                    $e->blocking,
                )),
            ]));

            return;
        } catch (LeadTimeTooShortException $e) {
            // Worded in the screen's own language from the values the refusal carries, with the
            // document named by its title. The minimum and both dates are what an operator needs to
            // fix it, so they stay in the sentence.
            $this->setStatus(__('legal-consent::ui.admin_status_deemed_window_rejected', [
                'reason' => __($e->label(), $e->replacements()),
            ]));

            return;
        } catch (NoticeTimelineInvertedException $e) {
            // The message carries the package's own numbers (which minimum, which dates), so it is
            // shown rather than replaced by a vaguer sentence of our own.
            $this->setStatus(__('legal-consent::ui.admin_status_deemed_window_rejected', [
                'reason' => $e->getMessage(),
            ]));

            return;
        } catch (LegalPublishRefused $e) {
            // Every other refusal the publisher makes: a major that must gate, a missing objection
            // deadline, a version lower than the active one. The message names the version and what
            // to publish instead, so it is shown whole.
            $this->setStatus(__('legal-consent::ui.admin_status_release_blocked', [
                'key' => app(NamesLegalTexts::class)->document($this->key),
                'reasons' => $e->getMessage(),
            ]));

            return;
        }

        // Over the WHOLE release, like the manager: this screen releases every locale too, and
        // `affects()` answers per (key, locale), so the first row named the people of one language.
        $affects = app(LegalDocumentReleaser::class)->affectsRelease($released);
        $this->setStatus(__('legal-consent::ui.admin_status_released', [
            'key' => app(NamesLegalTexts::class)->document($this->key),
            'count' => count($released),
            'affects' => $affects,
        ]));
    }

    public function render(): View
    {
        $set = LegalDraftSet::for($this->key);
        $draft = $set->draft($this->locale);

        $this->takeQueuedTranslation($draft);

        return view('legal-consent::livewire.legal-text-editor', [
            'translating' => $this->translating(),
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
        // The empty check is EQUIVALENT under mutation, measured 2026-09-14: Carbon refuses an empty
        // string with InvalidFormatException, which parseDate() turns into null as well. It stays so
        // that "left blank means not stated" does not rest on a parser's error path.
        return $value === '' ? null : $this->parseDate($value);
    }

    /**
     * One date-input value, or null when this screen cannot read it as the date it claims to be.
     *
     * NOT CarbonImmutable::parse(), AND NOT createFromFormat ALONE — measured, both let a wrong
     * date through, in different ways:
     *
     * - `parse()` never refuses. 'x' becomes TODAY and '31.02.2026' becomes 2026-03-03, silently.
     *   And when it does refuse, it throws InvalidFormatException, which extends
     *   InvalidArgumentException — no catch in releaseDeemed() takes it, so the operator gets a
     *   500 from a method whose own docblock promises never a fatal.
     * - `createFromFormat('!Y-m-d', …)` refuses 'x' and '31.02.2026' (by throwing, in Carbon's
     *   default strict mode) but STILL ROLLS OVER a well-formed impossible date: '2026-02-31'
     *   comes back as 2026-03-03 and '2026-13-01' as 2027-01-01. Both were measured here, and
     *   both are the shape a date field actually receives when someone types a day too far.
     *
     * So the format parse is the first half and the round-trip is the second: a date that does not
     * print back as what was typed is a date the input did not mean. The leading `!` resets the
     * time so two dates written the same way compare the same way.
     *
     * This matters because the value is the objection deadline of a deemed-consent release — the
     * moment silence starts binding people — frozen into an append-only proof row. A wrong one
     * cannot be corrected, only superseded by a new version.
     */
    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (InvalidFormatException) {
            return null;
        }

        // The instanceof is EQUIVALENT under mutation: createFromFormat() throws rather than returning
        // null, measured 2026-09-14 for an empty string and for garbage. Static analysis types the
        // result as nullable, which is why the guard stays.
        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    /**
     * The date fields carrying something this screen cannot read.
     *
     * Returned rather than thrown: the caller turns them into the same status line the package
     * already uses for a rejected window, so an operator who mistyped a date sees WHICH field —
     * not a 500, and not a silently dropped value that releases with no deadline at all.
     *
     * @return list<string>
     */
    private function unreadableDates(): array
    {
        $fields = [
            'announce date' => $this->announceAt,
            'objection deadline' => $this->objectionDeadline,
            'effective date' => $this->enforceAt,
        ];

        $unreadable = [];

        foreach ($fields as $label => $value) {
            if ($value !== '' && ! $this->parseDate($value) instanceof CarbonImmutable) {
                $unreadable[] = $label;
            }
        }

        return $unreadable;
    }

    /**
     * The document keys this instance has, as the manager derives them.
     *
     * @return list<string>
     */
    private function documentKeys(): array
    {
        $documents = config('legal-consent.documents');

        return is_array($documents) ? array_map(strval(...), array_keys($documents)) : [];
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

        // array_values() is EQUIVALENT under mutation: its readers, in_array() and a foreach in the
        // releaser, never read a key. It stays for the list<string> this returns; static analysis
        // rejects the removal (measured 2026-09-14).
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
