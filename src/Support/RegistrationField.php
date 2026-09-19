<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * Which form field evidences a document at registration.
 *
 * ONE place, for the reason {@see RegistrationAcknowledgment} gives about its own question: the
 * answer is needed in four (the validation rules, the messages, the checklist a form is built
 * from, and the evidence check the recorder performs), and a convention re-derived at four call
 * sites is a convention that drifts. Before this it was written out as `"legal_{$key}"` at each of
 * them, and they agreed by accident.
 *
 * ## Why a document may name its field at all
 *
 * A registration form is allowed to show ONE control for several pages — "I accept the terms and
 * have read the privacy policy" is an ordinary sign-up line, and 0.36.0 taught the ledger to
 * freeze exactly that sentence for each document behind it. The half that was missing is this one:
 * one control is one FIELD, and both the rule side and the evidence check still derived a separate
 * field per document. That left a consumer two ways through, and neither is acceptable on a
 * surface whose entire product is the proof of a human action:
 *
 *   - render a field nobody sees, so the rules find something; or
 *   - accept `legal-consent: recorded a mandatory consent with no registration-form field present`
 *     on every single registration, whose own hint names a completely different cause.
 *
 * So the operator says it, per document, in the registry:
 *
 *     'terms'   => ['legal_basis' => 'contract',        'registration_field' => 'legal_terms'],
 *     'privacy' => ['legal_basis' => 'acknowledgement', 'registration_field' => 'legal_terms'],
 *
 * This is the same shape as `admin.editor_route`: the package prescribes nothing, the consumer
 * declares, and a registry that declares none behaves exactly as it did. That last property is
 * load-bearing — every existing consumer keeps working word for word.
 *
 * ## What it deliberately does NOT rename
 *
 * THE CONTENT-HASH FIELD STAYS NAMED AFTER THE DOCUMENT, not after the control. `legal_{key}_hash`
 * carries one document's render-time fingerprint, and the accept-time guard it activates is about
 * a version released between page load and submit — a question each document answers for itself.
 * A single control covering four pages renders one checkbox and, if it wants the guard, four
 * hidden fields. Naming them after the control would collapse four fingerprints into one name and
 * silently guard whichever document was written last, which is worse than not guarding at all.
 */
final readonly class RegistrationField
{
    /**
     * The field that evidences this document — the declared name, or `legal_{key}`.
     *
     * Strictly a non-empty string, never truthy: a registry entry carrying `true`, `0` or a blank
     * string is an operator who started configuring and stopped, and guessing what they meant is
     * how a rule ends up naming a field no form renders. Such an entry falls back to the
     * convention, which is the behavior they had before they typed it.
     */
    public static function forDocument(string $key): string
    {
        $declared = DocumentRegistryEntry::option($key, 'registration_field');

        return is_string($declared) && trim($declared) !== ''
            ? trim($declared)
            : "legal_{$key}";
    }

    /**
     * The hidden field carrying this document's render-time fingerprint.
     *
     * Named after the DOCUMENT even when the control is shared — see the class docblock.
     */
    public static function hashForDocument(string $key): string
    {
        return "legal_{$key}_hash";
    }
}
