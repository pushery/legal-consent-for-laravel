<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Enums\DocumentType;

/**
 * Builds registration validation rules + messages from the document registry. Each
 * document becomes a `legal_{key}` field. Mandatory documents (contract/privacy) must
 * be `accepted`; a real consent is NEVER required (Kopplungsverbot Art. 7(4)) — it is
 * `nullable|boolean`. Acknowledgement messages say "zur Kenntnis genommen", never
 * "eingewilligt" (EDPB 05/2020 Rz. 122).
 *
 * With the age gate on (config `age_gate`), an `age_confirmed` field is additionally
 * required to be `accepted` — the Art. 8 DSGVO minimum-age attestation. The package
 * gates on the attestation; verifying the actual age remains the consuming app's job.
 */
final readonly class RegistrationRules
{
    /**
     * @param  array<string, array<string, mixed>>  $documents
     */
    public function __construct(
        private array $documents,
        private bool $ageGateEnabled = false,
        private int $ageThreshold = 16,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public function required(): array
    {
        $rules = [];

        foreach ($this->documents as $key => $config) {
            $rules["legal_{$key}"] = $this->typeFor($config)->requiresExplicitOptin()
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

        foreach ($this->documents as $key => $config) {
            $type = $this->typeFor($config);

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
     * @param  array<string, mixed>  $config
     */
    private function typeFor(array $config): DocumentType
    {
        $basis = $config['legal_basis'] ?? 'contract';

        return DocumentType::fromLegalBasis(is_string($basis) ? $basis : 'contract');
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
