<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Assembles display-ready view data for the consent UIs (the Livewire components and any
 * custom UI) from the active documents + the subject's ledger — grouped by the three legal
 * kinds so a screen never blends a contract, a notice, and a real consent. Tenant/locale
 * scoping is inherited from the underlying model queries and the gate's fold.
 */
final readonly class ConsentPresenter
{
    public function __construct(private ConsentGate $gate, private DocumentUrlResolver $urls) {}

    /**
     * The subject's standing across every active document in a locale, split into the three
     * legally distinct blocks. `held` is withdrawal-aware (the latest action, not a max).
     *
     * Each entry additionally carries `url` — where the document is readable, from the host's
     * configured resolver, or null when none is configured.
     *
     * @return array{contracts: list<array<string, mixed>>, acknowledgements: list<array<string, mixed>>, consents: list<array<string, mixed>>}
     */
    public function settingsFor(Model $subject, string $locale): array
    {
        $groups = ['contracts' => [], 'acknowledgements' => [], 'consents' => []];

        // Fold the subject's held majors ONCE, not once per document: hasCurrent() inside the loop
        // re-ran this full-ledger fold (and a per-document active-row query) for every document, so
        // the "My consents" screen cost 1 + 2N queries and N identical folds. This mirrors the
        // manager's statusFor(): one fold, one document query, the comparison in PHP. `held` keeps
        // its withdrawal/objection-aware semantics — it is the same fold hasCurrent() used.
        $standing = $this->gate->standingFor($subject);
        $held = $standing['held'];

        // Resolved ONCE, outside the loop: the bundled withdrawal route takes the document key as
        // a form field, so every entry that has one has the same URL. `Route::has()` rather than a
        // config read, because the route only exists if the package's route file registered it —
        // and a consumer may have loaded the config without the routes.
        $withdrawUrl = Route::has('legal-consent.web.withdraw')
            ? route('legal-consent.web.withdraw')
            : null;

        // The double opt-in's middle state, which `held` cannot express: entered but not yet
        // confirmed looks exactly like never entered. Without this the screen would invite the
        // subject to enter themselves a second time, and the second request would supersede the
        // first — so the confirmation link already in their inbox would stop working.
        $pendingConfirmation = $standing['pending'];

        // `locale` is selected even though it equals the argument: it is a document COLUMN, and the
        // host's URL resolver receives the model. A resolver that builds a per-locale route would
        // otherwise read null off a column that was simply never fetched.
        $documents = LegalDocument::query()
            ->select(['key', 'title', 'version', 'major_version', 'type', 'locale', 'requires_explicit_optin'])
            ->where('locale', $locale)
            ->where('is_active', true)
            ->orderBy('key')
            ->get();

        foreach ($documents as $document) {
            // Computed before the array rather than as a multi-line ternary inside it: a ternary
            // whose arms sit on their own lines leaves one of them unexecutable to line coverage
            // when the condition short-circuits earlier, which reads as an untested branch and is
            // really a formatting artifact.
            $offersWithdrawal = $withdrawUrl !== null
                && $document->type->isWithdrawable()
                && ($held[$document->key] ?? 0) >= $document->major_version;

            $entry = [
                'key' => $document->key,
                'title' => $document->title,
                'version' => $document->version,
                'held' => ($held[$document->key] ?? 0) >= $document->major_version,
                // `held === false` covers two different positions, and only one of them asks the
                // subject for anything: never accepted at all, versus a NEW MAJOR waiting. The
                // second one ends at the gate — the screen where it could have been done
                // voluntarily is the one that has to say so, or the package only ever compels
                // where it could have invited.
                //
                // Computed exactly as statusFor() does, including the opt-in exclusion: a
                // voluntary consent is never outstanding, because demanding one is Art. 7(4).
                'outstanding' => ! $document->requires_explicit_optin
                    && ($held[$document->key] ?? 0) < $document->major_version,
                'withdrawable' => $document->type->isWithdrawable(),
                // Null unless the host configured `document_url`. A settings screen on which the
                // document being withdrawn cannot be read is silent exactly where Art. 7(3) assumes
                // the subject knows what they are deciding about.
                'url' => $this->urls->for($document),
                // Where a plain HTML form posts to withdraw THIS entry — the bundled session route
                // when `routes.web` is on, and null otherwise. Null on every entry that is not a
                // withdrawable consent the subject actually holds, because a control offering to
                // withdraw something nobody holds is a button whose only outcome is an error.
                //
                // The stub shipped a form whose action fell back to `#` for want of this key, so it
                // looked like a working control and did nothing. A key that is null when there is
                // no route lets the stub render no form at all, which is the honest state.
                'pending_confirmation' => in_array($document->key, $pendingConfirmation, true),
                'withdraw_url' => $offersWithdrawal ? $withdrawUrl : null,
            ];

            $group = match ($document->type->legalBasis()) {
                'contract' => 'contracts',
                'acknowledgement' => 'acknowledgements',
                default => 'consents',
            };

            $groups[$group][] = $entry;
        }

        return $groups;
    }
}
