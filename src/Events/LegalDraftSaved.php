<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Pushery\LegalConsent\Models\LegalDraft;

/**
 * A draft's text changed — by a human (`origin = Authored`) or a machine translation
 * (`origin = Machine`).
 *
 * The actor is carried as an opaque string the caller supplies, not stored on the row: an editor
 * identity is personal data with no retention or erasure home in this package, and a draft is
 * mutable working state, not a ledger. Consumers that need "who edited this" log it from this
 * event under their own retention policy.
 */
final readonly class LegalDraftSaved
{
    use Dispatchable;

    public function __construct(
        public LegalDraft $draft,
        public ?string $actor = null,
    ) {}
}
