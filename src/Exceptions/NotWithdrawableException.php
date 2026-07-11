<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Pushery\LegalConsent\Enums\DocumentType;
use RuntimeException;

/**
 * Thrown when a withdrawal is attempted on a document that cannot be withdrawn. Only
 * a real consent (Art. 6(1)(a)) is withdrawable (Art. 7(3)); a contract ends by
 * cancellation and a privacy notice is information, so neither can be "withdrawn".
 */
final class NotWithdrawableException extends RuntimeException
{
    public static function for(string $documentKey, DocumentType $type): self
    {
        return new self(
            "Document '{$documentKey}' ({$type->value}) is not withdrawable — only a real consent can be withdrawn (Art. 7(3))."
        );
    }
}
