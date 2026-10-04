<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire\Concerns;

use Pushery\LegalConsent\Contracts\NamesLegalTexts;

/**
 * The sentence an admin screen adds to its release status when the release changed an acceptance
 * sentence.
 *
 * The acceptance sentence is frozen into a version and copied into every consent given under it, so
 * an operator should learn that it moved even when the text itself was the point of the release. A
 * document moved from Markdown to the drafts store takes its sentence from the translation files
 * rather than from its front matter, and that is how a sentence moves without anyone choosing it.
 */
trait NamesChangedSentences
{
    /**
     * A leading space and the sentence, or an empty string when no language changed. The languages
     * are named the way the rest of the screen names them, through {@see NamesLegalTexts}.
     *
     * @param  list<string>  $locales  from LegalDocumentReleaser::sentenceChangedIn()
     */
    private function sentenceChangeNotice(array $locales): string
    {
        if ($locales === []) {
            return '';
        }

        $names = app(NamesLegalTexts::class);

        return ' '.__('legal-consent::ui.admin_status_wording_changed', [
            'languages' => implode(', ', array_map($names->language(...), $locales)),
        ]);
    }
}
