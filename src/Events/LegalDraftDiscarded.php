<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Pushery\LegalConsent\Models\LegalDraft;

/**
 * A draft was thrown away: the row is gone, and the locale is back to having no draft at all.
 *
 * THE MODEL CARRIED HERE NO LONGER HAS A ROW BEHIND IT. It is the last state the draft had, so a
 * listener can say WHAT was discarded — key, locale, origin, review state, revision — without a
 * second query that would find nothing. Saving it would recreate what the caller just removed.
 *
 * The actor is an opaque string the caller supplies and is not stored, for the same reason
 * {@see LegalDraftSaved} gives: an editor identity is personal data with no retention or erasure
 * home in this package. A consumer that needs "who discarded this" logs it from here, under its own
 * retention policy — which is exactly what the consuming application was doing by hand before this
 * event existed, having no other way to record it.
 *
 * Nothing about the ledger changes. Discarding touches a draft, never a published version: a
 * published text is frozen evidence and has its own refusals.
 */
final readonly class LegalDraftDiscarded
{
    public function __construct(
        public LegalDraft $draft,
        public ?string $actor = null,
    ) {}
}
