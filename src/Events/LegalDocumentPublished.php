<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Pushery\LegalConsent\Models\LegalDocument;

/**
 * A new active version of a legal document was published (frozen into legal_documents
 * and activated). Internal listeners flush the gate cache; consumers may schedule
 * re-consent notices or their own side effects.
 */
final readonly class LegalDocumentPublished
{
    public function __construct(public LegalDocument $document) {}
}
