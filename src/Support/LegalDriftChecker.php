<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Content\SourceFactory;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Models\LegalDocument;
use Throwable;

/**
 * Detects "silent drift": the live source text no longer matches the active published
 * version's hash. That means an author changed the text without publishing a new version
 * and classifying its materiality — which must never take effect unnoticed. The check is
 * reporting-only; it never mutates anything.
 */
final readonly class LegalDriftChecker
{
    public function __construct(
        private SourceFactory $sources,
        private RenderPipeline $pipeline,
    ) {}

    /**
     * A human-readable drift reason for (key, locale), or null when the live source
     * matches the active published version (no drift).
     */
    public function driftFor(string $key, string $locale): ?string
    {
        $active = LegalDocument::query()
            ->select(['id', 'version', 'content_hash'])
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();

        try {
            $rendered = $this->pipeline->process($this->sources->for($key)->resolve($key, $locale));
        } catch (LegalDocumentNotFound) {
            // No source text to compare — not this checker's concern.
            return null;
        } catch (Throwable $e) {
            return "source for '{$key}' ({$locale}) could not be rendered: {$e->getMessage()}";
        }

        if (! $active instanceof LegalDocument) {
            return "'{$key}' ({$locale}) has source text but no published version — run legal-consent:publish.";
        }

        if ($active->content_hash !== $rendered->contentHash) {
            return "'{$key}' ({$locale}) source differs from published v{$active->version} — publish a new version and set its materiality.";
        }

        return null;
    }
}
