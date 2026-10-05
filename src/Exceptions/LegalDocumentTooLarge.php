<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * A source document exceeds the render pipeline's byte guard. Legal texts are small; a
 * multi-hundred-KB blob is almost always a mistake (or an attempt to make the renderer allocate
 * without bound), so the pipeline refuses it rather than rendering it. The limit is the
 * pipeline's own `MAX_BYTES`; the package sets no memory limit of its own and cannot know the
 * host's.
 */
final class LegalDocumentTooLarge extends RuntimeException
{
    /**
     * @param  int  $bytes  the size of the text that was refused
     * @param  int  $maxBytes  the limit it went over
     */
    public function __construct(
        string $message = '',
        public readonly int $bytes = 0,
        public readonly int $maxBytes = 0,
    ) {
        parent::__construct($message);
    }

    public static function for(string $type, string $locale, int $bytes, int $maxBytes): self
    {
        return new self(
            "Legal document '{$type}' ({$locale}) is {$bytes} bytes, exceeding the {$maxBytes}-byte limit.",
            $bytes,
            $maxBytes,
        );
    }
}
