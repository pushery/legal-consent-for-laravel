<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Illuminate\Queue\SerializesModels;
use Pushery\LegalConsent\Models\LegalConsent;

/**
 * A consent / acknowledgment / re-acceptance was appended to the ledger. Consumers
 * may react (analytics, mail, downstream sync); the package itself does not listen to it.
 */
final readonly class ConsentRecorded
{
    use SerializesModels;

    public function __construct(public LegalConsent $consent) {}
}
