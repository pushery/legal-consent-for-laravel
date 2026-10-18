<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * A source declared a source version that cannot be recorded as it stands.
 *
 * The source version is frozen onto the published row and folded into the hash of every consent
 * given to it, so it is held to what reads the same everywhere: printable ASCII, one to 64
 * characters, with no space at either end. A longer or a padded value is refused rather than
 * trimmed, because the trimmed one is a version the source never said.
 */
final class InvalidSourceVersion extends RuntimeException
{
    public static function for(string $type, string $locale, string $sourceVersion): self
    {
        $shown = json_encode($sourceVersion, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return new self(
            "Legal document '{$type}' ({$locale}) declares the source version {$shown}, which is not one to 64 printable ASCII characters without a space at either end. The source version is recorded on every consent given to the text, so it is refused rather than changed."
        );
    }
}
