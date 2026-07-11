<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Pushery\LegalConsent\Models\LegalConsent;

/**
 * A real consent was withdrawn (Art. 7(3)) — recorded as a new append-only entry.
 * Consumers should stop the withdrawn processing where no other legal basis applies
 * (Art. 17(1)(b)).
 */
final readonly class ConsentWithdrawn
{
    public function __construct(public LegalConsent $consent) {}
}
