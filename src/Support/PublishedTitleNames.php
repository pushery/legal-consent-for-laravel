<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Contracts\NamesLegalTexts;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * The default naming: a document is what its published row calls it, a language is its code.
 *
 * ## Why the title comes from the PUBLISHED row
 *
 * A draft's title is being worked on; the active row's title is what a reader of that document
 * actually sees, which is the thing an administrator is trying to recognize in a status line. It is
 * read in the READER's locale — a row published only in German names nothing an English screen
 * should print, and the catalog below has a better answer for that case than another language's
 * heading does.
 *
 * `legal-consent::titles.*` is that catalog: the same list the public pages are headed with,
 * translated, and it already answers the key itself for a document nobody has named. So the chain
 * is the reader's published row, then the catalog, and the key is where the catalog ends — the
 * honest answer for a document that has no name yet, rather than a fallback bolted on here.
 *
 * ## Why a language is answered as its code
 *
 * Nothing in this package knows that `fr` is called French, and the one library that does —
 * `ext-intl` — is not a requirement of this package. Making it one would mean every consumer
 * installs an extension to read a status line. An application that has language names (a switcher
 * usually does) binds {@see NamesLegalTexts} and returns them; the sentences stay where they are.
 */
final readonly class PublishedTitleNames implements NamesLegalTexts
{
    public function document(string $key): string
    {
        $preferred = LegalDocument::model()::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->where('locale', app()->getLocale())
            ->value('title');

        // The catalog, not the key, when nothing is published: `legal-consent::titles.*` is the same
        // list the public pages are headed with, it is translated, and it already answers the key
        // itself for a document nobody has named. One more fallback than the published row has, and
        // it is the difference between "Nutzungsbedingungen" and `terms` on a screen that refused a
        // release before anything of that document existed.
        return is_string($preferred) && $preferred !== '' ? $preferred : DocumentTitle::for($key);
    }

    public function language(string $locale): string
    {
        return $locale;
    }
}
