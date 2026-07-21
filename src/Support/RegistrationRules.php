<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Collection;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Builds registration validation rules + messages for the documents that are ACTUALLY PUBLISHED.
 * Each becomes a `legal_{key}` field. Mandatory documents (contract/notice) must be `accepted`; a
 * real consent is NEVER required (Kopplungsverbot Art. 7(4)) — it is `nullable|boolean`.
 * Acknowledgement messages say "zur Kenntnis genommen", never "eingewilligt" (EDPB 05/2020 Rz. 122).
 *
 * The resolution deliberately MIRRORS {@see RegistrationConsentRecorder}: the configured keys
 * intersected with the active rows, falling back to the default-locale version for a MANDATORY
 * document that is not published in the current locale. That is what keeps the three sides of a
 * registration honest — the rule side, the displayed checklist and the ledger row all resolve the
 * same document, so the form can never require a checkbox whose version is not the one recorded, nor
 * record a version it never showed.
 *
 * It is therefore DORMANT BY DEFAULT: a fresh project whose legal texts are still placeholders and
 * unpublished demands nothing. The consent section appears the moment `legal-consent:publish` runs,
 * and not a second earlier.
 *
 * With the age gate on (config `age_gate`), an `age_confirmed` field is additionally required to be
 * `accepted` — the Art. 8 DSGVO minimum-age attestation, which is about the person and not about a
 * document, so it does not depend on anything being published. The package gates on the attestation;
 * verifying the actual age remains the consuming app's job.
 */
final readonly class RegistrationRules
{
    /**
     * @param  array<string, array<string, mixed>>  $documents  the configured registration keys
     */
    public function __construct(
        private array $documents,
        private bool $ageGateEnabled = false,
        private int $ageThreshold = 16,
        private string $defaultLocale = 'de',
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public function required(): array
    {
        $rules = [];

        foreach ($this->resolvedTypes() as $key => $type) {
            $rules["legal_{$key}"] = $type->requiresExplicitOptin()
                ? ['nullable', 'boolean']
                : ['accepted'];
        }

        if ($this->ageGateEnabled) {
            $rules['age_confirmed'] = ['accepted'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->resolvedTypes() as $key => $type) {
            if ($type->requiresExplicitOptin()) {
                continue; // an optional consent has no "required" message
            }

            $messages["legal_{$key}.accepted"] = $this->message($type);
        }

        if ($this->ageGateEnabled) {
            $messages['age_confirmed.accepted'] = $this->ageMessage();
        }

        return $messages;
    }

    /**
     * The document type behind each configured key that is actually published — the SAME resolution
     * RegistrationConsentRecorder performs when it writes the proof. The type comes from the
     * resolved ROW, never from the config's `legal_basis`, so a document's legal nature is read from
     * the version that will be recorded.
     *
     * @return array<string, DocumentType>
     */
    private function resolvedTypes(): array
    {
        $locale = app()->getLocale();
        $active = $this->activeByKey($locale);
        $fallback = $locale === $this->defaultLocale ? $active : $this->activeByKey($this->defaultLocale);

        $resolved = [];

        foreach (array_keys($this->documents) as $key) {
            $key = (string) $key;
            $document = $active->get($key);

            if (! $document instanceof LegalDocument) {
                // Not published in this locale. A MANDATORY document is required regardless of
                // language, so it falls back to the default-locale version — exactly what the
                // recorder will freeze. An optional consent has nothing to fall back to.
                $candidate = $fallback->get($key);

                if ($candidate instanceof LegalDocument && $candidate->type->isMandatory()) {
                    $document = $candidate;
                }
            }

            if ($document instanceof LegalDocument) {
                $resolved[$key] = $document->type;
            }
        }

        return $resolved;
    }

    /**
     * @return Collection<string, LegalDocument>
     */
    private function activeByKey(string $locale): Collection
    {
        return LegalDocument::query()
            ->select(['key', 'type', 'locale'])
            ->where('locale', $locale)
            ->where('is_active', true)
            ->get()
            ->keyBy('key');
    }

    private function ageMessage(): string
    {
        $key = 'legal-consent::validation.age_required';
        $translated = trans($key, ['threshold' => $this->ageThreshold]);

        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        return "Bitte bestätige, dass du mindestens {$this->ageThreshold} Jahre alt bist.";
    }

    private function message(DocumentType $type): string
    {
        $isAcknowledgement = $type->legalBasis() === 'acknowledgement';

        $key = $isAcknowledgement
            ? 'legal-consent::validation.acknowledgement_required'
            : 'legal-consent::validation.contract_required';

        $translated = trans($key);

        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        return $isAcknowledgement
            ? 'Bitte bestätige, dass du die Datenschutzerklärung zur Kenntnis genommen hast.'
            : 'Bitte akzeptiere die Nutzungsbedingungen, um fortzufahren.';
    }
}
