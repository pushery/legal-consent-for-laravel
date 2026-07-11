<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * Thrown when something tries to UPDATE a recorded consent. The ledger is
 * append-only (Art. 5(2) GDPR accountability) — a correction is a new entry, never
 * a mutation of an existing one.
 */
final class LedgerImmutableException extends RuntimeException
{
    public static function onUpdate(): self
    {
        return new self(
            'legal_consents is append-only: a recorded consent must never be updated '
            .'(Art. 5(2) GDPR accountability). Append a new ledger entry instead.'
        );
    }
}
