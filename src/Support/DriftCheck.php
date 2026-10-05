<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * What the drift check found for one document in one language: the reason it reports, if any, and
 * whether a published version was compared with its source to find it.
 *
 * The two answers are separate because "no drift" means something only over what was compared. A
 * report that says nothing drifted has to be able to say how many published versions it looked at,
 * and zero is an answer worth seeing.
 */
final readonly class DriftCheck
{
    private function __construct(
        public ?string $reason,
        public bool $compared,
    ) {}

    /** A published version that matches what its source renders to. */
    public static function clean(): self
    {
        return new self(null, true);
    }

    /** Nothing to compare: no version and no text, or a text that is still being written. */
    public static function nothingToCompare(): self
    {
        return new self(null, false);
    }

    /** A reason to report, and whether a published version was compared to reach it. */
    public static function reported(string $reason, bool $compared): self
    {
        return new self($reason, $compared);
    }
}
