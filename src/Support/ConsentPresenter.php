<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Model;
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
        $held = $this->gate->heldMajorByKey($subject);

        // `locale` is selected even though it equals the argument: it is a document COLUMN, and the
        // host's URL resolver receives the model. A resolver that builds a per-locale route would
        // otherwise read null off a column that was simply never fetched.
        $documents = LegalDocument::query()
            ->select(['key', 'title', 'version', 'major_version', 'type', 'locale'])
            ->where('locale', $locale)
            ->where('is_active', true)
            ->orderBy('key')
            ->get();

        foreach ($documents as $document) {
            $entry = [
                'key' => $document->key,
                'title' => $document->title,
                'version' => $document->version,
                'held' => ($held[$document->key] ?? 0) >= $document->major_version,
                'withdrawable' => $document->type->isWithdrawable(),
                // Null unless the host configured `document_url`. A settings screen on which the
                // document being withdrawn cannot be read is silent exactly where Art. 7(3) assumes
                // the subject knows what they are deciding about.
                'url' => $this->urls->for($document),
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
