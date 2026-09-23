<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Pushery\LegalConsent\Contracts\NamesLegalTexts;
use Pushery\LegalConsent\Enums\BlockingReason;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\DraftOrigin;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Exceptions\LegalPublishRefused;
use Pushery\LegalConsent\Exceptions\LegalReleaseNotReady;
use Pushery\LegalConsent\Livewire\Concerns\AnnouncesStatus;
use Pushery\LegalConsent\Livewire\Concerns\AuthorizesLegalAdmin;
use Pushery\LegalConsent\Livewire\Concerns\WordsPublishRefusals;
use Pushery\LegalConsent\Models\LegalDraft;
use Pushery\LegalConsent\Support\DocumentMatrix;
use Pushery\LegalConsent\Support\LegalDocumentReleaser;
use Pushery\LegalConsent\Support\LegalDraftSet;

/**
 * The admin overview: one row per (document key × locale), plus a per-key "release all locales".
 *
 * Read-only over the draft/published state — every mutation happens in the editor or via the
 * releaser. It exists to make the one thing that can go wrong visible: a set that is not ready to
 * release, and why.
 *
 * Fail-closed behind {@see AuthorizesLegalAdmin}: with no `legal-consent.admin.ability` configured
 * it 404s, so a consumer cannot accidentally expose it.
 */
final class LegalTextManager extends Component
{
    use AnnouncesStatus;
    use AuthorizesLegalAdmin;
    use WordsPublishRefusals;

    /**
     * Whether the screen opens with its own page title.
     *
     * An application that mounts this inside its own admin frame already has one, and two page
     * titles on one screen is what a browser sweep rejects. Off, the frame carries the title and
     * the landmark takes its name directly instead of pointing at a heading that is no longer
     * there — an unnamed region is not an improvement on a duplicated one.
     *
     * The same switch the consent panel has carried since it met the same frame.
     */
    #[Locked]
    public bool $heading = true;

    public function mount(bool $heading = true): void
    {
        $this->heading = $heading;
    }

    public function releaseAll(string $key): void
    {
        // An action argument comes from the browser, so the key is client input and is checked
        // against the set this screen derives itself. Passed through, an unconfigured key reaches
        // the source factory, which has nothing to resolve for it and raises — leaving an admin
        // screen with a 500 for a request that is simply not a thing. 404, matching the rest of the
        // package: a key this instance does not have is one that does not exist here.
        abort_unless(in_array($key, $this->documentKeys(), true), 404);

        // The manager releases a material change in the mode its document type takes: an active
        // re-consent for a contract or a real consent, info-only for a privacy notice, silent for an
        // informational page. It used to fix an active re-consent for every key, which the publisher
        // refuses for a privacy notice, so the button failed for every privacy notice and a consuming
        // app had to fork this final class to release one. The deemed mode is a per-change legal call
        // made from the editor controls or the CLI, not a button on an overview grid.
        //
        // THAT SENTENCE NAMED TWO HOMES AND ONLY ONE OF THEM EXISTED, for as long as it has been
        // here. Measured 2026-09-05: `LegalTextEditor` carried nothing — no notice mode, no
        // objection deadline, no ReleaseOptions — so the only route to a deemed release was
        // `legal-consent:publish --deemed --objection-at=`. An application with an admin UI had no
        // in-app path to a capability this package implements end to end, and a consuming app
        // rebuilt the screen itself rather than adopt one that could not do it.
        //
        // The editor has it now ({@see LegalTextEditor::releaseDeemed()}), so the routing above is
        // true rather than aspirational. The placement stands on its own reasons: a deemed release
        // binds people by their SILENCE, and the editor is per (key, locale) — the context of "this
        // one change" is already there, and somebody has read the text.

        // No version is set here. The release derives it inside its own transaction, for this
        // screen and the editor alike ({@see LegalDocumentReleaser::nextVersionFor()}).
        try {
            $released = app(LegalDocumentReleaser::class)->release($key, $this->modeFor($key), $this->releaseLocalesFor($key));
        } catch (LegalReleaseNotReady $e) {
            // A polite live-region message — never a fatal — so a screen reader hears WHY the
            // release did not happen (WCAG 4.1.3), and nothing was written.
            $names = app(NamesLegalTexts::class);

            $this->setStatus(__('legal-consent::ui.admin_status_release_blocked', [
                'key' => $names->document($key),
                'reasons' => implode('; ', array_map(
                    // The reason is translated HERE, where it reaches a person. The exception keeps
                    // the English sentence for logs; a status line spoken by a screen reader has to
                    // be in the reader's language.
                    //
                    // …and so is the SUBJECT of the sentence, since the reasons stopped being the
                    // only translated half: an administrator read `terms (fr)` for a document the
                    // public site calls "Nutzungsbedingungen" in a language its own switcher offers
                    // as "Französisch". The package names what it owns and hands the rest to
                    // {@see NamesLegalTexts}.
                    static fn (string $locale, BlockingReason $reason): string => $names->language($locale).' ('.__($reason->label()).')',
                    array_keys($e->blocking),
                    $e->blocking,
                )),
            ]));

            return;
        } catch (LegalPublishRefused $e) {
            // A refusal of the version itself rather than of the set. It names the version and what
            // to publish instead, worded in this screen's language where it carries its reason, and
            // nothing was written, because the release is one transaction.
            $this->setStatus(__('legal-consent::ui.admin_status_release_blocked', [
                'key' => app(NamesLegalTexts::class)->document($key),
                'reasons' => $this->publishRefusalReason($e),
            ]));

            return;
        }

        // Over the WHOLE release, not over its first row. `affects()` answers per (key, locale) —
        // a consent row carries the language it was given in — so the first row named the people of
        // whichever language came back first. Measured in a seven-locale consumer: it said 0 where
        // one person was a major behind, under a sentence an operator reads to decide whether to
        // check the announcement again.
        $affects = app(LegalDocumentReleaser::class)->affectsRelease($released);
        $this->setStatus(__('legal-consent::ui.admin_status_released', [
            'key' => app(NamesLegalTexts::class)->document($key),
            'count' => count($released),
            'affects' => $affects,
        ]));
    }

    public function render(): View
    {
        $keys = $this->documentKeys();
        $names = app(NamesLegalTexts::class);

        $documentNames = array_combine($keys, array_map($names->document(...), $keys));
        $languageNames = array_combine($this->locales(), array_map($names->language(...), $this->locales()));

        return view('legal-consent::livewire.legal-text-manager', [
            'rows' => $this->grid($documentNames, $languageNames),
            'keys' => $keys,
            'locales' => $this->locales(),
            // Resolved once per key and handed to the view, rather than called from it. The binding
            // may answer from the database -- the shipped one does -- and a call inside the row loop
            // is how a guarded render starts paying per cell again.
            'documentNames' => $documentNames,
            'languageNames' => $languageNames,
        ]);
    }

    /**
     * The grid: per key, per locale, the one cell an admin reads before deciding to act.
     *
     * @param  array<string, string>  $documentNames  resolved once per key by the caller
     * @param  array<string, string>  $languageNames  resolved once per locale by the caller
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function grid(array $documentNames, array $languageNames): array
    {
        $grid = [];

        foreach ($this->documentKeys() as $key) {
            $set = LegalDraftSet::for($key);

            // Over the locales a release of THIS document would cover, not over every configured
            // one. Asked the wide way, an informational page with an untranslated locale showed a
            // blocked row beside a button that would have released it — the screen argued with
            // itself, and an operator had no way to tell which half was right.
            $blocking = $set->blockingLocales($set->releaseLocales($this->locales()));

            // One statement per key for the whole row, rather than one per cell. Asked per cell,
            // six documents in seven languages cost 42 of them on a component that re-renders on
            // every filter click.
            $unpublished = $set->unpublishedChanges($this->locales());

            foreach ($this->locales() as $locale) {
                $draft = $set->draft($locale);

                $cell = [
                    'written' => $draft instanceof LegalDraft,
                    'review_state' => $draft?->review_state->value,
                    // The raw value stays for anything that branches on state; the label is what a
                    // screen shows. Keeping both apart is what stops a storage token reaching a
                    // reader again the next time somebody renders the obvious field.
                    'review_state_label' => $draft?->review_state->label(),
                    'machine' => $draft?->origin === DraftOrigin::Machine,
                    'stale' => $draft instanceof LegalDraft && $set->isStale($draft),
                    'unpublished_changes' => $unpublished[$locale],
                    'publishable' => $draft instanceof LegalDraft && $set->isPublishable($draft),
                ];

                $cell['url'] = $this->editorUrl($key, $locale);
                $cell['label'] = $this->cellName($documentNames[$key] ?? $key, $languageNames[$locale] ?? $locale, $cell);

                $grid[$key][$locale] = $cell;
            }

            $grid[$key]['_release'] = [
                'ready' => $blocking === [],
                'blocking' => $blocking,
            ];
        }

        return $grid;
    }

    /**
     * Where this cell's text is edited, or null when the application has not said.
     *
     * The package ships no admin routes on purpose — which application shows a release button, and
     * at what address, is the application's decision — so the overview had no way to reach the
     * editor at all: you got to a text by typing its address. This is the seam for that, and it
     * stays opt-in.
     *
     * The parameters are bound BY POSITION, document first and locale second, rather than by name.
     * A name would be a contract this package cannot enforce: a route written as
     * `{document}/{locale}` and one written as `{key}/{lang}` are the same route to an operator,
     * and only positional binding accepts both.
     *
     * Asked of the ROUTER, not of the configuration. A name that is configured and not registered
     * is the ordinary state of a half-finished installation, and `route()` answers it by throwing —
     * on an overview, that is an error page instead of a screen. A misconfiguration degrades to a
     * cell that is not a link, and `legal-consent:doctor` is what says so out loud.
     */
    private function editorUrl(string $key, string $locale): ?string
    {
        $name = config('legal-consent.admin.editor_route');

        if (! is_string($name) || $name === '' || ! Route::has($name)) {
            return null;
        }

        try {
            return route($name, [$key, $locale]);
        } catch (UrlGenerationException) {
            // A registered route whose parameters this pair cannot fill. Same reasoning as above:
            // the overview keeps working, and the doctor is where an operator learns why.
            return null;
        }
    }

    /**
     * The accessible name of a linked cell — document, locale, and the states the badges show.
     *
     * The states are repeated here rather than left to the badges because an `aria-label` REPLACES
     * the content it sits on: a link wrapping "None" that announces only "Edit Terms (de)" hides
     * the one fact the cell exists to state. The words are the long ones, for the same reason the
     * badges carry them as a `title` — "Stale" is a column heading, "Needs update" is a sentence.
     *
     * Both names arrive already resolved. The binding may answer either of them from the database —
     * the shipped one does for a document — so calling it from inside the cell loop is how a render
     * whose budget is guarded starts paying per cell again. The caller asks once per key and once
     * per locale; this method only assembles.
     *
     * @param  array<string, mixed>  $cell
     */
    private function cellName(string $documentName, string $languageName, array $cell): string
    {
        $states = [];

        if ($cell['written'] !== true) {
            $states[] = (string) __('legal-consent::ui.admin_not_written');
        } else {
            $states[] = (string) __(is_string($cell['review_state_label']) ? $cell['review_state_label'] : '');

            if ($cell['machine'] === true) {
                $states[] = (string) __('legal-consent::ui.admin_machine');
            }

            if ($cell['stale'] === true) {
                $states[] = (string) __('legal-consent::ui.admin_needs_update');
            } elseif ($cell['unpublished_changes'] === true) {
                $states[] = (string) __('legal-consent::ui.admin_unpublished');
            }
        }

        // The outer punctuation lives in the translation, because it is punctuation: a language
        // that separates an apposition differently gets to say so without a change here.
        return (string) __('legal-consent::ui.admin_edit_for_state', [
            'key' => $documentName,
            'locale' => $languageName,
            'state' => implode(' · ', $states),
        ]);
    }

    /**
     * The locales this key is released in — the reasoning lives on the set, and so does the answer.
     *
     * It used to live HERE, privately, which is why the grid a few lines up and this screen's own
     * release button could disagree about one document ({@see LegalDraftSet::releaseLocales()}).
     *
     * @return list<string>
     */
    private function releaseLocalesFor(string $key): array
    {
        return LegalDraftSet::for($key)->releaseLocales($this->locales());
    }

    /** The mode a material change of this key's document type takes, from the documents registry. */
    private function modeFor(string $key): NoticeMode
    {
        return $this->typeFor($key)->materialChangeMode();
    }

    /** The document type this key is registered under, from the documents registry. */
    private function typeFor(string $key): DocumentType
    {
        $basis = config("legal-consent.documents.{$key}.legal_basis");

        return DocumentType::fromLegalBasis(is_string($basis) ? $basis : 'contract');
    }

    /** @return list<string> */
    private function documentKeys(): array
    {
        // THROUGH THE MATRIX, not off the raw config. A registry with two readers has two answers,
        // and only one of them is tested: this line stood identically in the manager and the editor
        // and neither filtered the list-config case, so `['terms', 'privacy']` reached the screens
        // as documents named `0` and `1` while every command skipped them.
        return DocumentMatrix::keys();
    }

    /** @return list<string> */
    private function locales(): array
    {
        $locales = config('legal-consent.locales');

        // array_values() is EQUIVALENT under mutation: its readers, in_array() and a foreach in the
        // releaser, never read a key. It stays for the list<string> this returns; static analysis
        // rejects the removal (measured 2026-09-14).
        return is_array($locales) ? array_values(array_filter($locales, is_string(...))) : [];
    }
}
