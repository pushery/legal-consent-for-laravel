<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * Thrown when a subject accepts a document whose active `content_hash` no longer matches the hash
 * they were shown — an admin released a new version between the moment the text was rendered and the
 * moment Accept was clicked. The append is refused so the ledger never freezes a version the subject
 * never actually read (Art. 7(1) "informed consent"): the append would be provable but prove the
 * wrong thing. Re-show the current version before recording consent. Consumer-mappable to HTTP 409.
 */
final class DocumentChangedException extends RuntimeException
{
    public function __construct(
        public readonly string $documentKey,
        public readonly string $expectedHash,
        public readonly string $actualHash,
    ) {
        parent::__construct(sprintf(
            'Document [%s] changed since it was shown (expected %s…, now %s…); re-show the current version before recording consent.',
            $documentKey,
            substr($expectedHash, 0, 12),
            substr($actualHash, 0, 12),
        ));
    }

    public static function for(string $documentKey, string $expectedHash, string $actualHash): self
    {
        return new self($documentKey, $expectedHash, $actualHash);
    }
}
