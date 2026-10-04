<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Content\AcceptanceWording;
use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\Scopes\TenantScope;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Verify the published documents themselves — the companion to `legal-consent:verify-ledger`
 * (which verifies the consent chain). Read-only: it never mutates a row, because a published row
 * is frozen proof and a "repair" would be exactly the tampering the guard exists to prevent.
 *
 * Four checks:
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
 * 4. PLACEHOLDER (advisory, or a hard error with `--placeholders-fail`) — an active row on version
 *    0.0.0, or whose content, title or acceptance sentence carries a marker from
 *    `legal-consent.placeholder_markers`, was released before its real text was written, and
 *    subjects are asked to agree to it. A warning by default, because a version frozen on 0.0.0 by
 *    an earlier release path stays in the table either way; a release check that must not ship a
 *    placeholder asks for the failure. Fixed, like the others, by publishing a new version.
 */
#[AsCommand(name: 'legal-consent:verify-documents')]
final class VerifyDocumentsCommand extends Command
{
    protected $signature = 'legal-consent:verify-documents
                            {--placeholders-fail : Count a placeholder in an active version as a failure, not as a warning}';

    protected $description = 'Verify published legal documents: content integrity, notice-mode consistency, wording locale, placeholders.';

    public function handle(RenderPipeline $pipeline): int
    {
        DB::disableQueryLog();

        /** @var list<string> $failures */
        $failures = [];
        /** @var list<string> $advisories */
        $advisories = [];

        $active = LegalDocument::model()::query()
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

            $placeholder = $this->placeholderIn($document);

            if ($placeholder !== null) {
                $finding = "'{$document->key}' ({$document->locale}) v{$document->version}: {$placeholder}, so subjects are asked to agree to a placeholder. Publish the real text as a new version.";

                if ($this->option('placeholders-fail') === true) {
                    $failures[] = $finding;
                } else {
                    $advisories[] = $finding;
                }
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

    /**
     * What makes this row a placeholder, or null when nothing does: version 0.0.0, or a configured
     * marker in the text a subject reads, which is the content, the title and the acceptance
     * sentence.
     */
    private function placeholderIn(LegalDocument $document): ?string
    {
        if ($document->version === '0.0.0') {
            return 'it stands on version 0.0.0';
        }

        $texts = ['content' => $document->content, 'title' => $document->title, 'ui_wording' => $document->ui_wording];

        foreach ($this->placeholderMarkers() as $marker) {
            foreach ($texts as $field => $text) {
                if ($text !== null && str_contains($text, $marker)) {
                    return "its {$field} carries the placeholder marker '{$marker}'";
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function placeholderMarkers(): array
    {
        $markers = config('legal-consent.placeholder_markers', ['LEGAL-PLACEHOLDER']);

        // An empty marker would be found in every text, so it is never one.
        return is_array($markers)
            ? array_values(array_filter($markers, static fn (mixed $marker): bool => is_string($marker) && $marker !== ''))
            : [];
    }

    private function cannedWording(string $key, string $locale): ?string
    {
        // In the locale's own language only. With the application's fallback locale in the
        // lookup, every locale without lines of its own answered with the fallback's sentence,
        // and this check could not tell a wording in the wrong language from the right one.
        return AcceptanceWording::for($key, $locale);
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

        // array_values() changes nothing observable, since every caller only iterates the list. It is
        // there for the list<string> return type.
        return is_array($locales) ? array_values(array_filter($locales, is_string(...))) : [];
    }
}
