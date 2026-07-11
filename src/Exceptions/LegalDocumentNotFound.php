<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * A content source could not resolve a document for a (type, locale).
 */
final class LegalDocumentNotFound extends RuntimeException
{
    public static function forSource(string $type, string $locale, ?string $reference = null): self
    {
        $where = $reference === null ? '' : " at [{$reference}]";

        return new self("No legal document found for type '{$type}' and locale '{$locale}'{$where}.");
    }
}
