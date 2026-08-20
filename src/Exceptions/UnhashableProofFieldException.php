<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a proof field handed to the tamper-evidence chain has no lossless string
 * form — an array, an object, or a bool.
 *
 * The chain hashes the STRING representation of each field on purpose, so that a driver
 * returning `2` and one returning `'2'` for the same column agree. A value that string-casts
 * lossily has no place in that scheme: `false` and an array both cast to `''`, which is also a
 * legitimate value, so folding them in would let three different rows share one hash. Refusing
 * is the only option that neither collides nor changes an existing row's hash.
 *
 * In practice this means the caller passed something that is not a database row — most often an
 * Eloquent model, whose casts turn four proof columns into enums and a date object.
 */
final class UnhashableProofFieldException extends InvalidArgumentException
{
    public static function for(string $field, mixed $value): self
    {
        return new self(sprintf(
            'The proof field [%s] holds a %s, which has no lossless string form. The consent '
            .'chain hashes database rows: pass the row as the driver returned it, not an Eloquent '
            .'model (its casts turn document_type, action, method and accepted_at into objects). '
            .'Hashing it anyway would write a link the verifier can never reproduce.',
            $field,
            get_debug_type($value),
        ));
    }
}
