<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * A new active version of a legal document was published (frozen into legal_documents
 * and activated). Internal listeners flush the gate cache; consumers may schedule
 * re-consent notices or their own side effects.
 *
 * Dispatched once the transaction that published the version commits. A release publishes all of
 * its languages in one transaction, so a release that fails in a later language dispatches nothing
 * for the earlier ones, and no listener acts on a version that was rolled back.
 */
final readonly class LegalDocumentPublished implements ShouldDispatchAfterCommit
{
    use SerializesModels;

    public function __construct(public LegalDocument $document) {}
}
