<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Pushery\LegalConsent\Enums\DocumentType;
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
        //
        // `id` is selected for the same reason, one step further: the resolver is handed the MODEL,
        // so the most ordinary thing a Laravel host can do with it — `route('legal.show', $document)`,
        // implicit route-model binding — reads the primary key. Without the column that read is null
        // and the seam throws a UrlGenerationException on a settings page, while the same closure
        // works on the re-consent gate, whose document set does select it.
        $documents = LegalDocument::query()
            ->select(['id', 'key', 'title', 'version', 'major_version', 'type', 'locale', 'requires_explicit_optin'])
            ->where('locale', $locale)
            ->where('is_active', true)
            // The same boundary statusFor() draws, drawn on the same side of it: an informational
            // page (an Impressum, a cookie policy) binds nobody, so it has no standing to report.
            // Nobody ever accepts one, which makes `held` false and `outstanding` permanently true —
            // a "your agreements" row for a page nobody agrees to, with a grant control next to it
            // that can only ever 404. The set is derived from the predicate rather than naming the
            // one type to exclude, so a future type is classified by isConsentBearing() alone.
            ->whereIn('type', array_map(
                static fn (DocumentType $type): string => $type->value,
                array_values(array_filter(
                    DocumentType::cases(),
                    static fn (DocumentType $type): bool => $type->isConsentBearing(),
                )),
            ))
            ->orderBy('key')
            ->get();

        // A document the subject still holds whose active version is gone. Retiring one is
        // `is_active = false`, which leaves the ledger untouched: the acceptance stands, the fold
        // reports it as held, and the application keeps processing on it — while the row it is
        // exercised from disappears from this screen. Art. 7(3) wants withdrawal to stay as easy as
        // granting was, and the manager already makes it WORK against a retired document; this is
        // what makes it visible. Same resolution as statusFor(), from the same class, so the screen
        // and the map a screen is built from cannot disagree.
        $retired = new RetiredHoldings()->forSubject(
            $subject,
            $held,
            array_values($documents->map(static fn (LegalDocument $document): string => $document->key)->all()),
        );

        /** @var list<array{LegalDocument, bool}> $rows */
        $rows = [];

        foreach ($documents as $document) {
            $rows[] = [$document, false];
        }

        foreach ($retired as $document) {
            $rows[] = [$document, true];
        }

        foreach ($rows as [$document, $isRetired]) {
            // Computed before the array rather than as a multi-line ternary inside it: a ternary
            // whose arms sit on their own lines leaves one of them unexecutable to line coverage
            // when the condition short-circuits earlier, which reads as an untested branch and is
            // really a formatting artifact.
            //
            // The holding test is "> 0", not ">= the active major", and the difference is the whole
            // point: after the operator publishes a new major of a voluntary consent, the subject
            // still holds the OLD one and the application still processes on it — `withdraw()`
            // executes against it without complaint. Comparing against the active major removed the
            // only control the package ships for Art. 7(3) at the moment the subject most plausibly
            // wants it, and a voluntary consent never reaches the re-consent screen either, because
            // it never gates (Art. 7(4)). The version difference still reaches the view through
            // `held` / `outstanding`.
            $offersWithdrawal = $withdrawUrl !== null
                && $document->type->isWithdrawable()
                && isset($held[$document->key]);

            $entry = [
                'key' => $document->key,
                'title' => $document->title,
                'version' => $document->version,
                // The column was already selected and then dropped here, so no settings view could
                // declare the language of a row it renders. It is not a formality: RetiredHoldings
                // orders by locale precisely so a retired row appears in the language the subject
                // read it in, which puts a German title on an English page BY DESIGN. See
                // {@see ContentLanguage} for what the views do with it.
                'locale' => $document->locale,
                // ⚠️ THIS READS `true` FOR EVERYONE WHEN `major_version` IS 0, and the two lines
                // below have the mirror of the same problem. `?? 0` cannot tell three states
                // apart — never acted, withdrawn (the fold drops an ENDING action to 0), and
                // genuinely holding major 0 — and at major 0 the comparison `x >= 0` is true for
                // all of them while `x < 0` is false for all of them.
                //
                // A document published as `0.9.0` therefore gates NOBODY: measured, outstanding()
                // returns an empty set for a subject with an empty ledger, while this screen tells
                // them they hold the contract. `major_version` is an unsignedInteger with no floor
                // at 1, and the draft writer accepts `0.9.0`, so it is reachable through the
                // ordinary publish path rather than only by hand.
                //
                // NOT fixed here on purpose, and the three lines below are deliberately NOT
                // written off as unobservable: repairing this changes whether real people are
                // blocked on an upgrade, which is a decision about enforcement rather than a
                // refactor. It is written up for the maintainer with the measurement.
                'held' => ConsentGate::holds($held, $document->key, $document->major_version),
                // `held === false` covers two different positions, and only one of them asks the
                // subject for anything: never accepted at all, versus a NEW MAJOR waiting. The
                // second one ends at the gate — the screen where it could have been done
                // voluntarily is the one that has to say so, or the package only ever compels
                // where it could have invited.
                //
                // Computed exactly as statusFor() does, including the opt-in exclusion: a
                // voluntary consent is never outstanding, because demanding one is Art. 7(4).
                //
                // A RETIRED holding is never outstanding either, and that is the deliberate half of
                // showing it at all. Nothing is being enforced — the gate reads the active set, so a
                // retired document cannot block anyone — and `outstanding` is the flag a screen
                // turns into "please accept this". Inviting an acceptance here would ask the subject
                // to agree to a version that is no longer published; the row exists to let them END
                // a holding, not to start one.
                'outstanding' => ! $isRetired
                    && ! $document->requires_explicit_optin
                    && ! ConsentGate::holds($held, $document->key, $document->major_version),
                'retired' => $isRetired,
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

            // ⚠️ THE TYPE BOUNDARY IS THE QUERY ABOVE, NOT THIS MATCH. The catch-all reads like one
            // — it is what filed an informational page under "consents", absorbing a basis it had
            // never been told about while nothing went red — but tightening it here cannot be the
            // fix: `legalBasis()` is typed `string`, so an exhaustive match is impossible, and an
            // explicit arm for a basis no shipped type has is a line no run can enter. What keeps a
            // page that binds nobody out of this loop is the `isConsentBearing()` filter on the
            // document set, which is also where statusFor() draws it.
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
