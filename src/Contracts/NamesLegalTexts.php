<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Contracts;

/**
 * What an admin screen calls a document and a language, so a status line reads like a sentence.
 *
 * The shipped manager used to put the configuration key and the locale code straight into its
 * messages: an administrator read `terms (fr)` for a document the public site calls
 * "Nutzungsbedingungen" in a language its own switcher offers as "Französisch". The REASONS in
 * those messages have been translated since 0.22.0, which makes the mismatch louder rather than
 * quieter — the reason arrives in the reader's language and the subject of the sentence does not.
 *
 * The package answers what it actually knows and no more. A published row carries a title, so a
 * document that has been published has a name; one that has not is still honestly its key. A
 * LANGUAGE has no name anywhere in this package, and the obvious source for one —
 * `Locale::getDisplayLanguage()` — lives in `ext-intl`, which this package does not require. Adding
 * it would make every consumer install an extension to read a status line, so the default answers
 * the code and this seam is where an application puts the names its own language switcher already
 * has.
 */
interface NamesLegalTexts
{
    /** What to call the document registered under this key. */
    public function document(string $key): string;

    /** What to call this locale. */
    public function language(string $locale): string;
}
