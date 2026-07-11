<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Content\SourceFactory;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Events\LegalDocumentPublished;
use Pushery\LegalConsent\Exceptions\LeadTimeTooShortException;
use Pushery\LegalConsent\Models\LegalDocument;
use RuntimeException;

/**
 * Freezes the current source text of a document into a new, immutable, active
 * `legal_documents` version — the artifact the gate enforces and the ledger snapshots.
 *
 * Materiality is a required human decision (never inferred): a material change becomes
 * a re-consent-forcing version. Re-publishing the same version with identical content is
 * a no-op; re-publishing it with different content is refused (bump the version instead).
 */
final readonly class LegalDocumentPublisher
{
    /** Minimum days between announcement and enforcement for a material change. */
    public const int MATERIAL_MIN_LEAD_DAYS = 60;

    /** Suggested minimum for a scheduled minor change (not enforced — minors never gate). */
    public const int MINOR_MIN_LEAD_DAYS = 14;

    /**
     * @param  array<string, array<string, mixed>>  $documents
     */
    public function __construct(
        private SourceFactory $sources,
        private RenderPipeline $pipeline,
        private array $documents,
    ) {}

    public function publish(
        string $key,
        string $locale,
        bool $isMaterial,
        ?CarbonImmutable $announceAt = null,
        ?CarbonImmutable $enforceAt = null,
    ): LegalDocument {
        $this->assertLocaleSupported($locale);

        $rendered = $this->pipeline->process($this->sources->for($key)->resolve($key, $locale));
        $type = $this->typeFor($key);

        $existing = LegalDocument::query()
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('version', $rendered->version)
            ->first();

        if ($existing instanceof LegalDocument) {
            if ($existing->content_hash === $rendered->contentHash) {
                if (! $existing->is_active) {
                    $existing->activate();
                }

                return $existing;
            }

            throw new RuntimeException(
                "Version {$rendered->version} of '{$key}' ({$locale}) already exists with different content — bump the version before publishing."
            );
        }

        // A major-version bump forces re-consent (the gate compares majors), so it must
        // be classified material — otherwise it would hard-block subjects with no grace
        // period or notice. The first version of a (key, locale) is exempt (no prior users).
        $previousMajor = LegalDocument::query()
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->value('major_version');

        if ($previousMajor !== null && is_numeric($previousMajor) && $rendered->majorVersion > (int) $previousMajor && ! $isMaterial) {
            throw new RuntimeException(
                "Version {$rendered->version} of '{$key}' ({$locale}) increases the major version, which forces re-consent — publish it as material."
            );
        }

        $now = CarbonImmutable::now();

        $announce = $announceAt ?? $rendered->announceAt ?? $now;
        $enforce = $enforceAt ?? $rendered->enforceAt ?? $now;

        // A material change SCHEDULED for a future enforcement date must give the full
        // grace period between its (effective) announcement and enforcement. Defaulting the
        // announce date to now before comparing closes the bypass where enforceAt is set
        // but announceAt is omitted (which would otherwise skip the guard on a short lead).
        // An immediate publish (enforcement now-or-past — e.g. an initial version) has no
        // grace window to honour and stays exempt.
        if ($isMaterial && $enforce->greaterThan($now) && $announce->addDays(self::MATERIAL_MIN_LEAD_DAYS)->greaterThan($enforce)) {
            throw LeadTimeTooShortException::for($key, self::MATERIAL_MIN_LEAD_DAYS, $announce, $enforce);
        }

        $document = LegalDocument::query()->create([
            'key' => $key,
            'type' => $type,
            'requires_explicit_optin' => $type->requiresExplicitOptin(),
            'locale' => $locale,
            'version' => $rendered->version,
            'major_version' => $rendered->majorVersion,
            'minor_version' => $rendered->minorVersion,
            'patch_version' => $rendered->patchVersion,
            'title' => $rendered->title,
            'content_format' => 'html',
            'content' => $rendered->html,
            'content_hash' => $rendered->contentHash,
            'ui_wording' => $rendered->uiWording,
            'source_driver' => $this->sourceNameFor($key),
            'source_reference' => $rendered->sourceRef,
            'requires_reconsent' => $isMaterial,
            'is_active' => false,
            'published_at' => $now,
            'announce_from' => $announce,
            'enforce_from' => $enforce,
        ]);

        $document->activate();

        event(new LegalDocumentPublished($document));

        return $document;
    }

    /**
     * Refuse to publish a version in a locale the app does not declare in
     * `legal-consent.locales` — an unlisted locale is almost always a typo, and shipping a
     * document nobody's gate/banner ever looks for is a silent proof gap. When the list is
     * empty/unset, any locale is allowed (no opinion).
     */
    private function assertLocaleSupported(string $locale): void
    {
        $locales = config('legal-consent.locales');

        if (! is_array($locales)) {
            return;
        }

        $supported = array_values(array_filter($locales, is_string(...)));

        if ($supported !== [] && ! in_array($locale, $supported, true)) {
            throw new RuntimeException(
                "Locale '{$locale}' is not in the configured legal-consent.locales (".implode(', ', $supported).'). Add it there, or publish a supported locale.'
            );
        }
    }

    private function typeFor(string $key): DocumentType
    {
        $basis = $this->documents[$key]['legal_basis'] ?? 'contract';

        return DocumentType::fromLegalBasis(is_string($basis) ? $basis : 'contract');
    }

    private function sourceNameFor(string $key): string
    {
        $source = $this->documents[$key]['source'] ?? 'database';

        return is_string($source) ? $source : 'database';
    }
}
