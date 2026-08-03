<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Pushery\LegalConsent\Enums\DocumentType;
use RuntimeException;

/**
 * Thrown when an objection is attempted on a document that cannot be objected to.
 *
 * An objection is one of exactly two things: a Widerspruch against a change deemed accepted by
 * silence (§ 308 Nr. 5 lit. a BGB), or an Art. 21 objection to processing on legitimate interest.
 * Neither reaches a real consent — Art. 21 explicitly does not cover consent-based processing,
 * and the instrument there is withdrawal (Art. 7(3)) — and neither reaches an informational page,
 * which binds nobody and so offers nothing to object to.
 *
 * The ledger is append-only, so a row claiming a state that does not legally exist cannot be
 * corrected later; it simply stands, and every later reader takes it at face value.
 */
final class NotObjectableException extends RuntimeException
{
    public static function for(string $documentKey, DocumentType $type): self
    {
        $reason = $type === DocumentType::ConsentOptin
            ? 'a consent is withdrawn, not objected to (Art. 7(3); Art. 21 does not cover consent-based processing)'
            : 'an informational page binds nobody, so there is nothing to object to';

        return new self(
            "Document '{$documentKey}' ({$type->value}) cannot be objected to — {$reason}."
        );
    }
}
