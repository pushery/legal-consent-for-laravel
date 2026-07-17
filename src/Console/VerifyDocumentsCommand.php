<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\Scopes\TenantScope;

/**
 * Verify the published documents themselves — the companion to `legal-consent:verify-ledger`
 * (which verifies the consent chain). Read-only: it never mutates a row, because a published row
 * is frozen proof and a "repair" would be exactly the tampering the guard exists to prevent.
 *
 * Three checks:
 *
 * 1. INTEGRITY (hard error) — every active row's stored `content` is re-hashed and compared to its
 *    stored `content_hash`. A mismatch means the two disagree: the row cannot prove what it claims.
 * 2. NOTICE-MODE DIVERGENCE (hard error) — a (key, major) whose active rows carry DIFFERENT notice
 *    modes across locales. Acceptance is identity-keyed (key + major), so a bound-by-silence
 *    DeemedAccepted holding of one locale would satisfy another locale's hard ActiveReconsent gate.
 *    Legacy data can carry this because the publisher classified per (key, locale). It is reported
 *    rather than repaired: the fix is publishing a new, consistent version — never editing frozen
 *    proof.
 * 3. WORDING LOCALE (advisory) — a row whose `ui_wording` is verbatim another locale's canned
 *    acceptance sentence. Before v0.4.0 the pipeline resolved the wording against the ambient app
 *    locale, so a `de` document could freeze the English sentence, which then flowed into every
 *    consent row's `ui_wording_snapshot`. Those rows are append-only and unfixable — surfaced so an
 *    operator knows, never touched.
 */
final class VerifyDocumentsCommand extends Command
{
    protected $signature = 'legal-consent:verify-documents';

    protected $description = 'Verify published legal documents: content integrity, notice-mode consistency, wording locale.';

    public function handle(RenderPipeline $pipeline): int
    {
        DB::disableQueryLog();

        /** @var list<string> $failures */
        $failures = [];
        /** @var list<string> $advisories */
        $advisories = [];

        $active = LegalDocument::query()
            ->withoutGlobalScope(TenantScope::class) // audit every tenant's documents
            ->where('is_active', true)
            ->orderBy('key')
            ->orderBy('locale')
            ->get();

        foreach ($active as $document) {
            if ($pipeline->hashOf($document->content) !== $document->content_hash) {
                $failures[] = "'{$document->key}' ({$document->locale}) v{$document->version}: stored content does not match its content_hash — the row cannot prove the text it carries.";
            }

            $foreign = $this->foreignWordingLocale($document);

            if ($foreign !== null) {
                $advisories[] = "'{$document->key}' ({$document->locale}) v{$document->version}: ui_wording is the '{$foreign}' acceptance sentence, not '{$document->locale}' — published before the wording locale fix; the row and its consent snapshots are frozen and cannot be corrected. Publish a new version to move forward.";
            }
        }

        foreach ($this->divergentNoticeModes($active) as $divergence) {
            $failures[] = $divergence;
        }

        foreach ($advisories as $advisory) {
            $this->warn($advisory);
        }

        if ($failures === []) {
            $this->info("Documents verified: {$active->count()} active version(s) intact and consistent.");

            return self::SUCCESS;
        }

        $this->error(sprintf('Document verification FAILED: %d problem(s) across %d active version(s).', count($failures), $active->count()));

        foreach ($failures as $failure) {
            $this->line("  • {$failure}");
        }

        return self::FAILURE;
    }

    /**
     * The locale whose canned acceptance sentence this row's ui_wording matches verbatim, when
     * that is NOT the row's own locale. Null when the wording is this locale's sentence or a
     * custom one the source supplied (which is legitimate and must not be flagged).
     */
    private function foreignWordingLocale(LegalDocument $document): ?string
    {
        if ($document->ui_wording === $this->cannedWording($document->key, $document->locale)) {
            return null;
        }

        foreach ($this->configuredLocales() as $locale) {
            if ($locale !== $document->locale && $document->ui_wording === $this->cannedWording($document->key, $locale)) {
                return $locale;
            }
        }

        return null;
    }

    private function cannedWording(string $key, string $locale): ?string
    {
        foreach (["legal-consent::wording.{$key}", 'legal-consent::wording.default'] as $translationKey) {
            $translated = trans($translationKey, [], $locale);

            if (is_string($translated) && $translated !== $translationKey) {
                return $translated;
            }
        }

        return null;
    }

    /**
     * A (key, major) is ONE change: every locale's active row for it must carry the same notice
     * mode, or a weaker-proof acceptance in one language satisfies a stronger requirement in
     * another.
     *
     * @param  Collection<int, LegalDocument>  $active
     * @return list<string>
     */
    private function divergentNoticeModes(Collection $active): array
    {
        /** @var array<string, array<string, list<string>>> $modes */
        $modes = [];

        foreach ($active as $document) {
            $identity = "{$document->key}@{$document->major_version}";
            $modes[$identity][$document->noticeMode()->value][] = $document->locale;
        }

        $divergences = [];

        foreach ($modes as $identity => $byMode) {
            if (count($byMode) < 2) {
                continue;
            }

            $detail = [];

            foreach ($byMode as $mode => $locales) {
                $detail[] = $mode.' ('.implode(', ', $locales).')';
            }

            $divergences[] = "'{$identity}' carries different notice modes across locales: ".implode(' vs ', $detail)
                .'. Acceptance is identity-keyed, so one locale\'s weaker-proof acceptance would satisfy another\'s gate. Publish a new version with one mode for every locale.';
        }

        return $divergences;
    }

    /** @return list<string> */
    private function configuredLocales(): array
    {
        $locales = config('legal-consent.locales');

        return is_array($locales) ? array_values(array_filter($locales, is_string(...))) : [];
    }
}
