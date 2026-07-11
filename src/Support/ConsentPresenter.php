<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Assembles display-ready view data for the consent UIs (the Livewire components and any
 * custom UI) from the active documents + the subject's ledger — grouped by the three legal
 * kinds so a screen never blends a contract, a notice, and a real consent. Tenant/locale
 * scoping is inherited from the underlying model queries and the manager.
 */
final readonly class ConsentPresenter
{
    public function __construct(private ConsentManager $consent) {}

    /**
     * The subject's standing across every active document in a locale, split into the three
     * legally distinct blocks. `held` is withdrawal-aware (the latest action, not a max).
     *
     * @return array{contracts: list<array<string, mixed>>, acknowledgements: list<array<string, mixed>>, consents: list<array<string, mixed>>}
     */
    public function settingsFor(Model $subject, string $locale): array
    {
        $groups = ['contracts' => [], 'acknowledgements' => [], 'consents' => []];

        $documents = LegalDocument::query()
            ->where('locale', $locale)
            ->where('is_active', true)
            ->orderBy('key')
            ->get();

        foreach ($documents as $document) {
            $entry = [
                'key' => $document->key,
                'title' => $document->title,
                'version' => $document->version,
                'held' => $this->consent->hasCurrent($subject, $document->key, $locale),
                'withdrawable' => $document->type->isWithdrawable(),
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
