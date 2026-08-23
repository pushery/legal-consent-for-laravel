<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * A mandatory document was about to be recorded from a request that carries NO `legal_<key>`
 * field — so nothing on this request evidences that a human ticked anything.
 *
 * Raised only under `legal-consent.registration.without_form_fields => 'refuse'`. The default is
 * `warn`, which logs and records, because the check can only look for the field name
 * `RegistrationRules` generates: an application with its own registration form, naming its fields
 * differently, validates the tick perfectly well and still sends no `legal_terms`. Refusing by
 * default would turn a correct application's registrations into failures on the very path every
 * current consumer uses.
 *
 * Where it IS right is the case the flag was written for: a sign-in through an external provider —
 * OAuth, SSO, an invitation link — raises `Registered` with no form behind it at all. Then the row
 * whose whole purpose is to prove a human acted gets written without one having, in an append-only
 * table nobody can correct afterwards.
 *
 * NOTHING is written when this is raised: the recorder resolves every document first and refuses
 * before its first `accept()`, so a registration either records all of its consents or none.
 */
final class UnevidencedConsentException extends RuntimeException
{
    /**
     * @param  list<string>  $documentKeys  the mandatory keys with no field on the request
     */
    public static function for(array $documentKeys): self
    {
        return new self(sprintf(
            'Refusing to record mandatory consent to [%s]: the request carries no %s field, so nothing '
            .'here evidences that the subject was asked. This is '
            .'legal-consent.registration.without_form_fields => \'refuse\'. If your registration form '
            .'names its fields differently, set it back to \'warn\'; if you sign people in through an '
            .'external provider, turn off registration.listen_to_registered_event and record the first '
            .'acceptance at an interstitial under ConsentMethod::FirstUseGate.',
            implode(', ', $documentKeys),
            implode(' / ', array_map(static fn (string $key): string => "legal_{$key}", $documentKeys)),
        ));
    }
}
