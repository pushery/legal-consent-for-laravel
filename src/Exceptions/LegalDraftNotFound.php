<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * A draft was expected to exist and does not — reviewing or versioning a text nobody has written.
 *
 * Distinct from {@see LegalDocumentNotFound} on purpose: that one is the publish gate's refusal
 * (and the drift checker catches it to stay quiet about work in progress), while this is a caller
 * asking to act on a row that is simply absent, which should surface rather than pass silently.
 */
final class LegalDraftNotFound extends RuntimeException
{
    public static function for(string $key, string $locale): self
    {
        return new self("No draft exists for '{$key}' ({$locale}).");
    }
}
