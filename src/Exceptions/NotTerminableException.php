<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Pushery\LegalConsent\Enums\DocumentType;
use RuntimeException;

/**
 * Thrown when a termination is attempted on a document that is not a contract.
 *
 * The free right to terminate before a change takes effect exists against a CONTRACT
 * (§ 675g Abs. 2 BGB for a payment contract, § 327r Abs. 3 for digital products, P2B for a
 * platform's terms). There is nothing to terminate in a privacy notice, which is information; in
 * a consent, which is withdrawn; or in an informational page, which binds nobody.
 *
 * The ledger is append-only, so a "Terminated" row against a privacy notice cannot be taken back
 * — and it reads to any later reader as a contract that ended.
 */
final class NotTerminableException extends RuntimeException
{
    public static function for(string $documentKey, DocumentType $type): self
    {
        return new self(
            "Document '{$documentKey}' ({$type->value}) cannot be terminated — the free right to terminate applies to a contract (§ 675g / § 327r Abs. 3 BGB, P2B), and nothing else here is one."
        );
    }
}
