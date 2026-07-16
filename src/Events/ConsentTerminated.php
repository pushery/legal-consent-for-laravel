<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Pushery\LegalConsent\Models\LegalConsent;

/**
 * A subject exercised a free right to terminate before a change took effect (§ 675g /
 * § 327r Abs. 3 BGB / P2B) — recorded as a new append-only entry. The consuming app owns
 * the actual contract end (cancel the subscription, close the account); the package emits
 * the signal and records the provable ledger entry.
 */
final readonly class ConsentTerminated
{
    public function __construct(public LegalConsent $consent) {}
}
