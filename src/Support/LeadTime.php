<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Enums\LeadTimeBasis;

/**
 * The minimum advance period of a change, in days, with the calendar months the law counts where
 * it counts them and the rule that set it.
 */
final readonly class LeadTime
{
    public function __construct(
        public int $days,
        public ?int $months,
        public LeadTimeBasis $basis,
        public ?string $regime,
    ) {}
}
