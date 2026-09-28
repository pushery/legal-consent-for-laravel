<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * A draft was written after it was shown for review, so the sign-off was refused.
 *
 * A sign-off is the human approval of the exact text a reviewer read, and it makes that text
 * publishable. A translation that lands, or a second editor saving, between the reviewer reading the
 * draft and pressing the button would otherwise carry that approval without anyone having read it.
 */
final class LegalDraftChanged extends RuntimeException
{
    public static function sinceShown(string $key, string $locale): self
    {
        return new self("The draft for '{$key}' ({$locale}) was written after it was shown for review, so it was not marked reviewed. Review the current text.");
    }
}
