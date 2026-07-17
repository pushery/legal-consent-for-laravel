<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content\Drivers;

use Pushery\LegalConsent\Content\ContentFormat;
use Pushery\LegalConsent\Content\LegalDocumentSource;
use Pushery\LegalConsent\Content\RawDocument;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Exceptions\MissingAcceptanceWording;
use Pushery\LegalConsent\Models\LegalDraft;
use Pushery\LegalConsent\Support\LegalDocumentPublisher;
use Pushery\LegalConsent\Support\LegalDraftSet;
use Pushery\LegalConsent\Support\LegalDriftChecker;

/**
 * Reads the admin-maintained draft store — and IS the publish gate.
 *
 * The gate lives here, in `resolve()`, rather than in a publish wrapper, and that placement is the
 * whole architecture: `LegalDocumentPublisher` resolves its text through the configured source, so
 * `php artisan legal-consent:publish terms de --active` and an admin screen's release button are
 * literally the same code path. A check anywhere else would be a *procedural* promise that the CLI
 * walks straight past; here it is *structural* — there is one door, and no unreviewed, stale, or
 * machine-drafted text fits through it.
 *
 * Throwing {@see LegalDocumentNotFound} is deliberate and load-bearing, not a convenience:
 * {@see LegalDriftChecker} catches exactly this class and returns
 * null, so a text that is merely mid-edit stays SILENT in the daily drift check, while
 * {@see LegalDocumentPublisher} surfaces it as a hard failure. That
 * keeps `check-drift` sharp — it reports only the one actionable state, "approved legal text is
 * not live" — instead of crying every day about work in progress until someone removes it from
 * the schedule.
 */
final readonly class DraftDocumentSource implements LegalDocumentSource
{
    public function resolve(string $type, string $locale): RawDocument
    {
        $set = LegalDraftSet::for($type);
        $draft = $set->draft($locale);

        // Unreviewed, stale, or machine-origin-unreviewed text is NOT a source. A machine
        // translation lands as ReviewState::Draft and only an explicit human act sets Reviewed,
        // so this single check is what makes "a machine draft can never be published" true on
        // EVERY path — the admin button and the CLI alike.
        if (! $draft instanceof LegalDraft || ! $set->isPublishable($draft)) {
            throw LegalDocumentNotFound::forSource($type, $locale, 'legal_drafts');
        }

        return new RawDocument(
            type: $type,
            locale: $locale,
            title: $this->titleFor($type, $locale),
            // Already RenderPipeline output: the writer sanitized it before storage, so the
            // pipeline re-runs the SAME sanitizer over already-clean bytes (idempotent) rather
            // than a second allowlist disagreeing with the one the ledger hashes.
            body: $draft->body,
            format: ContentFormat::Html,
            // ONE version for every locale of a release, read from the source-locale row.
            version: $set->version(),
            uiWording: $this->wordingFor($type, $locale),
            sourceRef: 'legal_drafts#'.$draft->id.'@'.$draft->revision,
        );
    }

    /**
     * The cache fingerprint. Deliberately UNGATED — it must see mid-edit state, or a preview would
     * serve a stale render. `revision` is monotonic and collision-free, unlike an `updated_at`
     * stamp, which has second granularity and would let two edits within one second share a
     * fingerprint and serve the older text for the cache's whole TTL.
     */
    public function fingerprint(string $type, string $locale): string
    {
        $draft = LegalDraft::query()
            ->select(['id', 'revision'])
            ->where('key', $type)
            ->where('locale', $locale)
            ->first();

        return $draft instanceof LegalDraft ? $draft->id.':'.$draft->revision : 'missing';
    }

    /**
     * The document's heading, in its OWN locale. Vendor lang, never draft content — so it cannot
     * be machine-translated into the frozen row by accident.
     */
    private function titleFor(string $type, string $locale): string
    {
        $key = "legal-consent::titles.{$type}";
        $translated = trans($key, [], $locale);

        return is_string($translated) && $translated !== $key ? $translated : $type;
    }

    /**
     * The acceptance sentence, resolved in the DOCUMENT's locale and passed explicitly rather than
     * left to the pipeline's ambient-locale lookup. It is vendor lang, not draft content, which
     * makes the one sentence a subject clicks "I accept" on structurally un-machine-translatable.
     */
    private function wordingFor(string $type, string $locale): string
    {
        foreach (["legal-consent::wording.{$type}", 'legal-consent::wording.default'] as $key) {
            $translated = trans($key, [], $locale);

            if (is_string($translated) && $translated !== $key) {
                return $translated;
            }
        }

        throw MissingAcceptanceWording::for($type, $locale);
    }
}
