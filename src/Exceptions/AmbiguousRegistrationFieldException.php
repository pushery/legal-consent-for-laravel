<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * Two documents sit behind one registration control, and they cannot share a rule.
 *
 * A collective control is allowed and ordinary — "I accept the terms and have read the privacy
 * policy" covers two mandatory documents, both of which need `accepted`, and that resolves to one
 * rule with nothing lost. What cannot be expressed is a MANDATORY document sharing a control with
 * a real consent: the first needs `accepted`, and making the second `accepted` is exactly the
 * coupling Art. 7(4) DSGVO forbids. There is no honest single rule.
 *
 * Refused HERE rather than resolved to whichever document the loop reached last, because the
 * silent version of this is a consent that was never freely given sitting in a ledger whose whole
 * purpose is to demonstrate that it was. A registry is read at boot and fails the same way on
 * every request, so the operator meets this in development; the alternative is that their users
 * meet it in production, on a record nobody can repair afterwards.
 */
final class AmbiguousRegistrationFieldException extends RuntimeException
{
    public static function for(string $field, string $firstKey, string $secondKey): self
    {
        return new self(sprintf(
            'Documents [%s] and [%s] both declare registration_field => %s, but one is mandatory '
            .'and the other is a consent, so no single validation rule is truthful for that control: '
            .'a mandatory document needs `accepted`, and requiring a consent is the coupling '
            .'Art. 7(4) forbids. Give the consent its own control, or drop its registration_field.',
            $firstKey,
            $secondKey,
            $field,
        ));
    }
}
