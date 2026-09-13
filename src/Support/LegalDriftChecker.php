<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Content\Document;
use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Content\SourceFactory;
use Pushery\LegalConsent\Exceptions\InvalidDocumentVersion;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Exceptions\MissingAcceptanceWording;
use Pushery\LegalConsent\Models\LegalDocument;
use Throwable;

/**
 * Detects "silent drift": what the live source renders to no longer matches the active published
 * version. That means a change is sitting in front of readers without a version and a materiality
 * classification behind it — which must never take effect unnoticed. The check is reporting-only;
 * it never mutates anything.
 *
 * ⚠️ AND IT SAYS WHICH KIND OF CHANGE IT IS, BECAUSE THE TWO NEED OPPOSITE ANSWERS. A differing
 * content hash used to be reported as "source differs — publish a new version and set its
 * materiality", whoever or whatever had moved. Registering CommonMark's TableExtension then made
 * every document holding a table differ, and the report sent operators into a materiality decision
 * about texts nobody had edited. Since migration 000030 a row records the hash of its source and a
 * fingerprint of the renderer, so the three cases separate: the TEXT moved, only its PRESENTATION
 * moved, or both — and a row that predates those columns is reported as the unknown it is.
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
            ->select(['id', 'version', 'content_hash', 'source_hash', 'render_fingerprint'])
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();

        try {
            $rendered = $this->pipeline->process($this->sources->for($key)->resolve($key, $locale));
        } catch (LegalDocumentNotFound) {
            // No source text to compare — not this checker's concern.
            return null;
        } catch (InvalidDocumentVersion|MissingAcceptanceWording $e) {
            // A source that cannot even be rendered into a valid document is a specific,
            // actionable drift — surface it clearly rather than as a vague "could not be
            // rendered", which reads like an infrastructure hiccup and hides the real cause.
            return "'{$key}' ({$locale}) source is invalid — {$e->getMessage()}";
        } catch (Throwable $e) {
            return "source for '{$key}' ({$locale}) could not be rendered: {$e->getMessage()}";
        }

        if (! $active instanceof LegalDocument) {
            return "'{$key}' ({$locale}) has source text but no published version — run legal-consent:publish.";
        }

        if ($active->content_hash !== $rendered->contentHash) {
            return $this->classify($active, $rendered, $key, $locale);
        }

        return null;
    }

    /**
     * WHY the published HTML and the live one differ, in the operator's terms.
     *
     * The order is the order of consequence. A changed text is a legal decision and outranks
     * everything else, so it is stated first and never softened by a rendering change riding
     * along — that pairing is named explicitly rather than merged, because a re-publish that
     * silences the rendering would freeze the text change in the same breath.
     */
    private function classify(LegalDocument $active, Document $rendered, string $key, string $locale): string
    {
        $where = "'{$key}' ({$locale})";
        $renderer = $active->render_fingerprint !== $rendered->renderFingerprint;

        // Neither column can be filled after the fact: the bytes that produced this row are gone,
        // and hashing today's source would assert the text was unchanged at publish time with
        // nothing having checked. So the row is reported as undecidable, and the next ordinary
        // publish — which the operator has to make anyway if the text really did move — ends it.
        if ($active->source_hash === null) {
            return "{$where} renders differently than published v{$active->version}, and that version predates the source hash — whether the text or only its rendering changed cannot be decided from the row. Compare the text yourself, then publish a new version; from then on this check can tell the two apart.";
        }

        if ($active->source_hash !== $rendered->sourceHash) {
            return $renderer
                ? "{$where} TEXT changed since v{$active->version}, and the renderer changed as well — publish a new version and set its materiality. Classify the text change; the presentation change is frozen with it."
                : "{$where} TEXT changed since v{$active->version} — publish a new version and set its materiality.";
        }

        if ($renderer) {
            return "{$where} PRESENTATION only: the text is byte-for-byte the one published as v{$active->version}, and the renderer moved. Run `legal-consent:rerender {$key} {$locale}` to re-freeze it — no materiality decision is owed.";
        }

        // Everything the row records matches and the HTML still differs, so the cause is outside
        // what the fingerprint covers — the sanitizer's own traversal, or CommonMark at a version
        // it already had. Naming that is the honest answer; attributing it to the text would be a
        // guess, and attributing it to the renderer would claim a proof this row does not carry.
        return "{$where} renders differently than published v{$active->version} although the source text and the recorded renderer both match — the cause is outside what the fingerprint covers (the HTML sanitizer itself, or CommonMark at the same version). Compare the published HTML with the current render before deciding.";
    }
}
