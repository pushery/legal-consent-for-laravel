<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Pushery\LegalConsent\Enums\DocumentType;
use RuntimeException;

/**
 * Thrown when acceptance is recorded against a document class that carries no consent.
 *
 * An informational page — an Impressum, a cookie policy, an accessibility statement — is published
 * because the law requires it to be readable, and it asks the reader for nothing. There is no
 * sentence to accept, so a ledger row saying someone accepted it asserts a state that never
 * existed. The ledger is append-only: such a row cannot be corrected later, it simply stands, and
 * every later reader takes it as proof.
 *
 * The sibling of {@see NotObjectableException} and {@see NotTerminableException} for the two entry
 * points that append an acceptance rather than a transition — `accept()` and `record()`.
 */
final class NotConsentBearingException extends RuntimeException
{
    public static function for(string $documentKey, DocumentType $type): self
    {
        return new self(
            "Document '{$documentKey}' ({$type->value}) cannot be accepted — an informational page "
            .'binds nobody, so there is no acceptance to record.'
        );
    }
}
