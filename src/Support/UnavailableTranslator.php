<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Contracts\LegalTextTranslator;
use Pushery\LegalConsent\Exceptions\TranslatorNotConfigured;

/**
 * The default binding: no translator. Fails LOUD rather than pretending.
 *
 * A null-object that quietly returned the source text would be the worst possible default here —
 * it would file the untranslated original as a translation, a human would review it against a
 * source it matches word for word, and the app would end up with a German contract published under
 * an English locale. Refusing is the only honest thing an absent translator can do.
 */
final readonly class UnavailableTranslator implements LegalTextTranslator
{
    public function translate(string $html, string $sourceLocale, string $targetLocale): string
    {
        throw TranslatorNotConfigured::forLocale($targetLocale);
    }
}
