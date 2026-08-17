<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Fired once per version, BEFORE its audience is notified — and cancelable.
 *
 * The package had no observability at all on the notice path: a sweep that mailed a hundred
 * thousand people and one that mailed nobody looked the same from outside, and an operator who
 * wanted to hold a specific version back had no seam to do it from.
 *
 * ⚠️ CANCELING SUPPRESSES A LEGALLY REQUIRED COMMUNICATION. Under P2B Art. 3(3) a change
 * implemented without its notice is void; under § 675g the fiction of consent needs the notice to
 * have been delivered. So this is for an operational emergency — a wrong audience, a broken mail
 * configuration — never for routine throttling, which is what `notifications.max_recipients_per_run`
 * is for. A held version keeps its watermark UNSTAMPED, so the notice stays owed and the next sweep
 * picks it up: this defers a notice, it does not waive one.
 *
 * Not `readonly`, unlike every other event here, and the mutable property IS the feature: a
 * listener writes `$cancel` and the sweep reads it back. Dispatch it as an OBJECT (`event($e)`),
 * never through a static helper that would build a second instance from its arguments and leave
 * the listener's decision on something nobody reads.
 */
final class NoticeDispatching
{
    /** Set to true in a listener to hold this version back for this run. */
    public bool $cancel = false;

    public function __construct(
        public readonly LegalDocument $version,
        public readonly int $recipients,
    ) {}
}
