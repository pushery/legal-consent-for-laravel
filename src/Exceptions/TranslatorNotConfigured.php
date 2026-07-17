<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * Something asked for a machine translation while no translator is bound.
 *
 * The package ships only the seam, never a provider: every app already has its own LLM wiring, and
 * a second one inside a package would fight it. So this is the honest answer to "translate this"
 * when nobody has said how — rather than returning the source text unchanged, which would file the
 * untranslated original as a translation and put it in front of a reviewer who would find it
 * matching the source perfectly.
 */
final class TranslatorNotConfigured extends RuntimeException
{
    public static function forLocale(string $locale): self
    {
        return new self(
            "No translator is bound, so '{$locale}' cannot be machine-translated. Bind your own "
            .'implementation of Pushery\LegalConsent\Contracts\LegalTextTranslator in a service '
            .'provider — the package ships the seam and the human-review gate, never a provider.'
        );
    }
}
