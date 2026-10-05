<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * Thrown when something tries to UPDATE a row of an append-only table: a recorded consent, or the
 * proof that a notice was delivered. Both are evidence (Art. 5(2) GDPR accountability), so a
 * correction is a new row, never a change to an existing one. Each table has its own message,
 * because the two are corrected in different ways.
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

    public static function onNoticeUpdate(): self
    {
        return new self(
            'legal_notices is append-only: the proof that a notice was delivered must never be updated '
            .'(Art. 5(2) GDPR accountability). A notice sent again is proved by a row of its own once '
            .'it is delivered.'
        );
    }
}
