<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Fired once per version AFTER its sweep finished — with the number the sweep handed to the queue.
 *
 * The counterpart of {@see NoticeDispatching}, and the more useful half in practice: it is the
 * signal that says how far a legally required communication reached. A run that quietly reaches
 * nobody is the defect this package has already paid for once, and a metric on this event is what
 * makes it visible without reading a log.
 *
 * THE TWO NUMBERS MEASURE DIFFERENT MOMENTS, and the gap between them is the useful part.
 * `$notified` is what this run QUEUED. `$proofed` is how many append-only `legal_notices` rows
 * stand for this version by the time the sweep ends — evidence of actual delivery, written when a
 * notice leaves the mailer rather than when its job is enqueued. With a synchronous queue the two
 * agree; with a worker `$proofed` is whatever has been delivered so far, which is usually nothing
 * yet, and a metric watching it climb afterwards is watching the notices arrive. It stays at
 * nought for good when `durable_medium.proof` is off, or when the notice goes out on a channel
 * outside `durable_medium.channels`.
 */
final readonly class NoticeDispatched
{
    public function __construct(
        public LegalDocument $version,
        public int $notified,
        public int $proofed,
    ) {}
}
