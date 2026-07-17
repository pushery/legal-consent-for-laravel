<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Pushery\LegalConsent\Enums\DraftOrigin;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Exceptions\LegalReleaseNotReady;
use Pushery\LegalConsent\Livewire\Concerns\AuthorizesLegalAdmin;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\LegalDraft;
use Pushery\LegalConsent\Support\LegalDocumentReleaser;
use Pushery\LegalConsent\Support\LegalDraftSet;

/**
 * The admin overview: one row per (document key × locale), plus a per-key "release all locales".
 *
 * Read-only over the draft/published state — every mutation happens in the editor or via the
 * releaser. It exists to make the one thing that can go wrong visible: a set that is not ready to
 * release, and why.
 *
 * Fail-closed behind {@see AuthorizesLegalAdmin}: with no `legal-consent.admin.ability` configured
 * it 404s, so a consumer cannot accidentally expose it.
 */
final class LegalTextManager extends Component
{
    use AuthorizesLegalAdmin;

    public string $status = '';

    public function releaseAll(string $key): void
    {
        // The manager releases the common case — an initial version or a re-consent change, both of
        // which gate. The deemed/info-only modes are a per-change legal call made from the editor
        // controls or the CLI, not a button on an overview grid.
        try {
            $released = app(LegalDocumentReleaser::class)->release($key, NoticeMode::ActiveReconsent, $this->locales());
        } catch (LegalReleaseNotReady $e) {
            // A polite live-region message — never a fatal — so a screen reader hears WHY the
            // release did not happen (WCAG 4.1.3), and nothing was written.
            $this->status = "'{$key}' was not released: ".implode('; ', array_map(
                static fn (string $locale, string $reason): string => "{$locale} ({$reason})",
                array_keys($e->blocking),
                array_values($e->blocking),
            ));

            return;
        }

        $first = $released->first();
        $affects = $first instanceof LegalDocument ? app(LegalDocumentReleaser::class)->affects($first) : 0;
        $this->status = "Released '{$key}' across ".count($released)." locale(s) — affects {$affects} subject(s).";
    }

    public function render(): View
    {
        return view('legal-consent::livewire.legal-text-manager', [
            'rows' => $this->grid(),
            'keys' => $this->documentKeys(),
            'locales' => $this->locales(),
        ]);
    }

    /**
     * The grid: per key, per locale, the one cell an admin reads before deciding to act.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function grid(): array
    {
        $grid = [];

        foreach ($this->documentKeys() as $key) {
            $set = LegalDraftSet::for($key);
            $blocking = $set->blockingLocales($this->locales());

            foreach ($this->locales() as $locale) {
                $draft = $set->draft($locale);

                $grid[$key][$locale] = [
                    'written' => $draft instanceof LegalDraft,
                    'review_state' => $draft?->review_state->value,
                    'machine' => $draft?->origin === DraftOrigin::Machine,
                    'stale' => $draft instanceof LegalDraft && $set->isStale($draft),
                    'unpublished_changes' => $draft instanceof LegalDraft && $set->hasUnpublishedChanges($draft),
                    'publishable' => $draft instanceof LegalDraft && $set->isPublishable($draft),
                ];
            }

            $grid[$key]['_release'] = [
                'ready' => $blocking === [],
                'blocking' => $blocking,
            ];
        }

        return $grid;
    }

    /** @return list<string> */
    private function documentKeys(): array
    {
        $documents = config('legal-consent.documents');

        return is_array($documents) ? array_map(strval(...), array_keys($documents)) : [];
    }

    /** @return list<string> */
    private function locales(): array
    {
        $locales = config('legal-consent.locales');

        return is_array($locales) ? array_values(array_filter($locales, is_string(...))) : [];
    }
}
