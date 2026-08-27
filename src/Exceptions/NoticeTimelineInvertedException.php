<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Carbon\CarbonInterface;
use Pushery\LegalConsent\Models\LegalDocument;
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
 */
final class NoticeTimelineInvertedException extends RuntimeException
{
    public static function for(string $documentKey, string $locale, CarbonInterface $announceAt, CarbonInterface $enforceAt): self
    {
        return new self(sprintf(
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
}
