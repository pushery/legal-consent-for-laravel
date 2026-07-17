<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;

/**
 * The release-time inputs of a publish — the operator's legal classification of ONE change.
 *
 * Deliberately not columns on `legal_drafts`: these describe the change being released, not the
 * text being edited, and persisting them on a draft would leave eight fields nobody clears
 * between releases.
 */
final readonly class ReleaseOptions
{
    public function __construct(
        public ?string $changeClass = null,
        public ?string $regime = null,
        public ?CarbonImmutable $announceAt = null,
        public ?CarbonImmutable $enforceAt = null,
        public ?CarbonImmutable $objectionDeadline = null,
        public bool $offersTermination = false,
        public bool $keepsUnmodified = false,
    ) {}
}
