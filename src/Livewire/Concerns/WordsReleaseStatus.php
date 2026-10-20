<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Pushery\LegalConsent\Contracts\NamesLegalTexts;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\IntegerSetting;
use Pushery\LegalConsent\Support\LegalDocumentReleaser;

/**
 * The status line an admin screen shows once a release is written.
 *
 * It names the document, the number of languages the release covered, and how many people it
 * reaches, counted over the whole release rather than its first row: a consent carries the language
 * it was given in, so the first row alone would name the people of whichever language came back
 * first. The count gets `admin.release_count_seconds`. When the database stops it at that limit, the
 * line names the release without the number, because the release itself is written either way.
 */
trait WordsReleaseStatus
{
    /**
     * @param  Collection<int, LegalDocument>  $released
     */
    private function releasedStatus(string $key, Collection $released): string
    {
        $values = [
            'key' => app(NamesLegalTexts::class)->document($key),
            'count' => count($released),
        ];

        $affects = app(LegalDocumentReleaser::class)->affectsReleaseWithin($released, $this->releaseCountSeconds());

        if ($affects === null) {
            return (string) __('legal-consent::ui.admin_status_released_uncounted', $values);
        }

        return (string) __('legal-consent::ui.admin_status_released', [...$values, 'affects' => $affects]);
    }

    /**
     * Null when the setting is null, which counts without a limit. A value that is not a whole
     * number gets the shipped limit of 5, and `legal-consent:doctor` names it.
     */
    private function releaseCountSeconds(): ?int
    {
        $value = config('legal-consent.admin.release_count_seconds', 5);

        if ($value === null) {
            return null;
        }

        return IntegerSetting::from($value) ?? 5;
    }
}
