<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Pushery\LegalConsent\Events\LegalDocumentPublished;
use Pushery\LegalConsent\Support\EnforceableDocumentCache;

/**
 * Drops the enforceable-set cache the moment a new version goes live.
 *
 * Runs AFTER COMMIT, and that is not a detail: the atomic releaser publishes every locale inside
 * one transaction, so a listener firing synchronously would forget the cache while the new rows
 * are still uncommitted — the very next request would re-read the OLD set and cache it again, and
 * the release would stay invisible until the TTL expired. Not queued, because forgetting a cache
 * key is cheap and must not depend on a worker being alive; `ShouldHandleEventsAfterCommit` gives
 * the ordering without the queue.
 */
final readonly class FlushEnforceableCacheOnPublish implements ShouldHandleEventsAfterCommit
{
    public function __construct(private EnforceableDocumentCache $cache) {}

    public function handle(LegalDocumentPublished $event): void
    {
        $this->cache->flushAll();
    }
}
