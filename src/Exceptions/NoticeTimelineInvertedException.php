<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Carbon\CarbonInterface;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\DocumentTitle;
use RuntimeException;

/**
 * A version was published with an announcement date AFTER the date it takes effect.
 *
 * The create migration states the timeline as an invariant — `published_at < announce_from <
 * enforce_from` — and nothing enforced it. Inverted, the change binds before anybody is told:
 * the banner shows a change only from `announce_from`, and the notice sweep sends only from
 * `announce_from`, while the gate blocks from `enforce_from`. A subject is locked out before the
 * package is willing to tell them why, which is the exact sequence § 308 Nr. 5 BGB and Art. 3(3)
 * Reg. (EU) 2019/1150 make ineffective.
 *
 * It also freezes a NEGATIVE `notice_period_days` into a row that cannot be corrected — the column
 * is outside {@see LegalDocument::MUTABLE_AFTER_PUBLISH} — and that
 * number is what a consumer's compliance report reads as "the notice period we granted".
 *
 * Distinct from {@see LeadTimeTooShortException}, which is about a period that is real but too
 * short. This one is about a period that does not exist.
 *
 * Like that one, it carries the values it was built from. The message is an English sentence for a
 * log or a stack trace; a screen a person reads words it through `label()` and `replacements()`, in
 * its own language. It used to reach the editor's status line as the English sentence alone, inside
 * a translated one.
 */
final class NoticeTimelineInvertedException extends RuntimeException
{
    public function __construct(
        public readonly string $documentKey,
        public readonly string $locale,
        public readonly CarbonInterface $announceAt,
        public readonly CarbonInterface $enforceAt,
    ) {
        parent::__construct(sprintf(
            "'%s' (%s) was given an announcement date (%s) AFTER its effective date (%s). A change cannot take "
            .'effect before it is announced: the banner and the notice sweep both start at the announcement, so '
            .'subjects would be bound — and, in a gating mode, blocked — before anything reaches them. Announce '
            .'first, then set the effective date after it.',
            $documentKey,
            $locale,
            $announceAt->toDateTimeString(),
            $enforceAt->toDateTimeString(),
        ));
    }

    public static function for(string $documentKey, string $locale, CarbonInterface $announceAt, CarbonInterface $enforceAt): self
    {
        return new self($documentKey, $locale, $announceAt, $enforceAt);
    }

    /** The translation key for a screen a person reads. Its placeholders are those of `replacements()`. */
    public function label(): string
    {
        return 'legal-consent::ui.notice_timeline_inverted';
    }

    /**
     * The placeholders of `label()`, with the document named by its title in `$locale`.
     *
     * The dates are days, not the timestamps the English message carries: a person picked them in a
     * date field, and a midnight appended to each is noise on a screen.
     *
     * @return array{document: string, announce: string, enforce: string}
     */
    public function replacements(?string $locale = null): array
    {
        return [
            'document' => DocumentTitle::for($this->documentKey, $locale),
            'announce' => $this->announceAt->toDateString(),
            'enforce' => $this->enforceAt->toDateString(),
        ];
    }
}
