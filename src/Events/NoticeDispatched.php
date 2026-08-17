<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Fired once per version AFTER its sweep finished — with the number actually notified.
 *
 * The counterpart of {@see NoticeDispatching}, and the more useful half in practice: it is the
 * only signal that says a legally required communication went out, and how far it reached. A run
 * that quietly reaches nobody is the defect this package has already paid for once, and a metric
 * on this event is what makes it visible without reading a log.
 *
 * `$proofed` counts the append-only `legal_notices` rows written for this version in this run. It
 * is the durable-medium evidence count and can legitimately be lower than `$notified` — a channel
 * outside `durable_medium.channels` notifies without proving.
 */
final readonly class NoticeDispatched
{
    public function __construct(
        public LegalDocument $version,
        public int $notified,
        public int $proofed,
    ) {}
}
