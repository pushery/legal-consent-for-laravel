<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Pushery\LegalConsent\Models\LegalConsent;

/**
 * A consent / acknowledgement / re-acceptance was appended to the ledger. Consumers
 * may react (analytics, mail, downstream sync); the package itself only updates its cache.
 */
final readonly class ConsentRecorded
{
    public function __construct(public LegalConsent $consent) {}
}
