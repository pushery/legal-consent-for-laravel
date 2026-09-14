<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Carbon\CarbonInterface;
use Pushery\LegalConsent\Enums\LeadTimeSpan;
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
        /**
         * The END of the span that was measured — the objection deadline or the enforcement date,
         * depending on `$span`.
         *
         * ⚠️ IT IS STILL CALLED `enforceAt`, AND THAT NAME IS WHY THE DEFECT LASTED. The deemed-
         * consent branch has always passed the objection deadline into it, so a reader of this
         * class saw "enforce" and wrote a sentence about enforcement. The name stays because it is
         * public readonly state that a host may already read; `$span` is what says what it holds,
         * and every sentence this class produces now goes through that instead of through the name.
         */
        public readonly CarbonInterface $enforceAt,
        public readonly LeadTimeSpan $span = LeadTimeSpan::Enforcement,
    ) {
        parent::__construct(sprintf(
            "Material change to '%s' needs at least %d days between announcement (%s) and %s (%s).",
            $documentKey,
            $minDays,
            $announceAt->toDateString(),
            $span === LeadTimeSpan::ObjectionDeadline ? 'the deadline for objections' : 'enforcement',
            $enforceAt->toDateString(),
        ));
    }

    public static function for(
        string $documentKey,
        int $minDays,
        CarbonInterface $announceAt,
        CarbonInterface $enforceAt,
        LeadTimeSpan $span = LeadTimeSpan::Enforcement,
    ): self {
        return new self($documentKey, $minDays, $announceAt, $enforceAt, $span);
    }

    /** The translation key for a screen a person reads. Its placeholders are those of `replacements()`. */
    public function label(): string
    {
        return $this->span->label();
    }

    /**
     * The placeholders of `label()`, with the document named by its title in `$locale`.
     *
     * The end date's placeholder is named by the SPAN, so it cannot end up under a word that
     * describes a different date. `enforce` for the enforcement span, `deadline` for the objection
     * one — and the sentence each key carries uses exactly the one it is paired with.
     *
     * @return array{document: string, days: int, announce: string, enforce: string}|array{document: string, days: int, announce: string, deadline: string}
     */
    public function replacements(?string $locale = null): array
    {
        /** @var array{document: string, days: int, announce: string, enforce: string}|array{document: string, days: int, announce: string, deadline: string} $replacements */
        $replacements = [
            'document' => DocumentTitle::for($this->documentKey, $locale),
            'days' => $this->minDays,
            'announce' => $this->announceAt->toDateString(),
            $this->span->endPlaceholder() => $this->enforceAt->toDateString(),
        ];

        return $replacements;
    }
}
