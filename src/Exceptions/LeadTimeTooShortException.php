<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Carbon\CarbonInterface;
use Pushery\LegalConsent\Support\DocumentTitle;
use RuntimeException;

/**
 * A material change was scheduled with too little lead time between announcement and
 * enforcement. The default minimum (60 days) traces to § 675g Abs. 1 BGB ("spätestens
 * zwei Monate") and BGH XI ZR 26/20 — the only defensible statutory anchor for an
 * "angemessene Frist" on an intrusive change.
 *
 * The refusal carries the values it was built from. The message is an English sentence for a log
 * or a stack trace; a screen a person reads words it through `label()` and `replacements()`, in its
 * own language and with the document's title rather than its configuration key.
 */
final class LeadTimeTooShortException extends RuntimeException
{
    public function __construct(
        public readonly string $documentKey,
        public readonly int $minDays,
        public readonly CarbonInterface $announceAt,
        public readonly CarbonInterface $enforceAt,
    ) {
        parent::__construct(sprintf(
            "Material change to '%s' needs at least %d days between announcement (%s) and enforcement (%s).",
            $documentKey,
            $minDays,
            $announceAt->toDateString(),
            $enforceAt->toDateString(),
        ));
    }

    public static function for(string $documentKey, int $minDays, CarbonInterface $announceAt, CarbonInterface $enforceAt): self
    {
        return new self($documentKey, $minDays, $announceAt, $enforceAt);
    }

    /** The translation key for a screen a person reads. Its placeholders are those of `replacements()`. */
    public function label(): string
    {
        return 'legal-consent::ui.lead_time_too_short';
    }

    /**
     * The placeholders of `label()`, with the document named by its title in `$locale`.
     *
     * @return array{document: string, days: int, announce: string, enforce: string}
     */
    public function replacements(?string $locale = null): array
    {
        return [
            'document' => DocumentTitle::for($this->documentKey, $locale),
            'days' => $this->minDays,
            'announce' => $this->announceAt->toDateString(),
            'enforce' => $this->enforceAt->toDateString(),
        ];
    }
}
