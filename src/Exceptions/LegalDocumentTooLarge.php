<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * A source document exceeds the render pipeline's byte guard. Legal texts are small;
 * a multi-hundred-KB blob is almost always a mistake (or an attempt to exhaust the
 * 128 MB CLI memory budget), so the pipeline refuses it rather than rendering it.
 */
final class LegalDocumentTooLarge extends RuntimeException
{
    public static function for(string $type, string $locale, int $bytes, int $maxBytes): self
    {
        return new self(
            "Legal document '{$type}' ({$locale}) is {$bytes} bytes, exceeding the {$maxBytes}-byte limit."
        );
    }
}
