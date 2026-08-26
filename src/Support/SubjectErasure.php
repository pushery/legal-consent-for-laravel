<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * What an Art. 17 erasure did, per ledger.
 *
 * Returned rather than logged, because the caller is the one who knows where their audit trail
 * lives — and an erasure that reports nothing back cannot be recorded by the application that was
 * legally obliged to perform it.
 *
 * `rechained` is separate from `consents` on purpose: it counts the rows whose chain link had to be
 * recomputed, which is what the operator correlates against the next `legal-consent:verify-ledger`.
 * It is normally the same number, and it is NOT when a subject also has rows that predate
 * tamper-evidence — those carry no link and are erased without one.
 */
final readonly class SubjectErasure
{
    public function __construct(
        public int $consents = 0,
        public int $notices = 0,
        public int $rechained = 0,
    ) {}

    /** Did this erasure touch anything at all? */
    public function isEmpty(): bool
    {
        return $this->consents === 0 && $this->notices === 0;
    }
}
