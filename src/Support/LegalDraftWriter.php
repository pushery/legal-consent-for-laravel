<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Content\ContentFormat;
use Pushery\LegalConsent\Content\RawDocument;
use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Enums\DraftOrigin;
use Pushery\LegalConsent\Enums\ReviewState;
use Pushery\LegalConsent\Events\LegalDraftReviewed;
use Pushery\LegalConsent\Events\LegalDraftSaved;
use Pushery\LegalConsent\Exceptions\LegalDraftNotFound;
use Pushery\LegalConsent\Models\LegalDraft;

/**
 * The ONLY thing that writes a draft's bytes — so one class owns the invariant that
 * `legal_drafts.body` is always RenderPipeline output.
 *
 * That invariant is the security boundary for the first `{!! !!}` sink a consumer will build on
 * this: text is sanitized on the way IN, by the same pipeline whose output the ledger hashes.
 * A second sanitizer anywhere would mean two allowlists — the ledger hashing one form while the
 * page renders another.
 *
 * Exactly three writers of `source_hash`, and the split is deliberate:
 *
 *  - save()             a human typed → review resets to Draft. `source_hash` is NOT touched, so
 *                       editing the SOURCE locale stales every translation by derivation, with
 *                       zero rows written — and editing a STALE translation cannot un-stale it.
 *  - markReviewed()     the explicit human sign-off → stamps `source_hash` from the source row.
 *                       Reviewing IS the act of confirming this text against the current source,
 *                       so it is the one place a human may assert freshness.
 *  - applyTranslation() the machine → records the source hash it translated FROM and stays Draft.
 *                       Fresh but unreviewed: proof that the two predicates are independent.
 *
 * There is NO path from applyTranslation() to Reviewed. That is what makes "a machine draft can
 * never be published" structural rather than a matter of discipline.
 */
/**
 * Not `final`: `find()` is a `protected` seam so a test can force the stale read a concurrent writer
 * sees (find returns null while the row exists) and exercise the insert→unique-violation→converge
 * path deterministically, which no single-connection test could otherwise provoke.
 */
readonly class LegalDraftWriter
{
    public function __construct(private RenderPipeline $pipeline) {}

    /**
     * A human wrote this text. Sanitizes, resets the review, bumps the revision — and does nothing
     * at all when the normalized bytes are unchanged, so an idle save never stales a translation
     * or invalidates a cache.
     */
    public function save(string $key, string $locale, string $body, ?string $actor = null): LegalDraft
    {
        $normalized = $this->normalize($key, $locale, $body);
        $hash = $this->pipeline->hashOf($normalized);

        $draft = $this->find($key, $locale);

        if ($draft instanceof LegalDraft && $draft->content_hash === $hash) {
            return $draft; // byte-identical after normalization: not a change
        }

        $draft = $this->persist($key, $locale, [
            'body' => $normalized,
            'content_hash' => $hash,
            'review_state' => ReviewState::Draft->value,
            'origin' => DraftOrigin::Authored->value,
        ], $draft);

        event(new LegalDraftSaved($draft, $actor));

        return $draft;
    }

    /**
     * The explicit human sign-off on these EXACT bytes — the only writer of Reviewed, and the only
     * path to a publishable draft. Stamps the source hash: reviewing a translation is asserting it
     * says what the source says right now.
     */
    public function markReviewed(string $key, string $locale, ?string $actor = null): LegalDraft
    {
        $set = LegalDraftSet::for($key);
        $draft = $set->draft($locale);

        if (! $draft instanceof LegalDraft) {
            throw LegalDraftNotFound::for($key, $locale);
        }

        $sourceHash = $draft->locale === $this->sourceLocale() ? null : $set->source()?->content_hash;

        $draft = $this->persist($key, $locale, [
            'review_state' => ReviewState::Reviewed->value,
            'source_hash' => $sourceHash,
        ], $draft);

        event(new LegalDraftReviewed($draft, $actor));

        return $draft;
    }

    /**
     * A machine produced this translation. Records WHICH source text it was made from, and leaves
     * the draft unreviewed — fresh but unpublishable until a human signs it off.
     */
    public function applyTranslation(string $key, string $locale, string $body, string $translatedFromHash, ?string $actor = null): LegalDraft
    {
        $normalized = $this->normalize($key, $locale, $body);

        $draft = $this->persist($key, $locale, [
            'body' => $normalized,
            'content_hash' => $this->pipeline->hashOf($normalized),
            'source_hash' => $translatedFromHash,
            'review_state' => ReviewState::Draft->value,
            'origin' => DraftOrigin::Machine->value,
        ], $this->find($key, $locale));

        event(new LegalDraftSaved($draft, $actor));

        return $draft;
    }

    /** Set the version the next release will carry. Kept on the source row; read for every locale. */
    public function setVersion(string $key, string $version): LegalDraft
    {
        $locale = $this->sourceLocale();
        $draft = $this->find($key, $locale);

        if (! $draft instanceof LegalDraft) {
            throw LegalDraftNotFound::for($key, $locale);
        }

        return $this->persist($key, $locale, ['version' => $version], $draft);
    }

    /**
     * Run the text through the one pipeline, so what is stored is exactly what a publish freezes.
     */
    private function normalize(string $key, string $locale, string $body): string
    {
        return $this->pipeline->process(new RawDocument(
            type: $key,
            locale: $locale,
            title: $key,
            body: $body,
            format: ContentFormat::Html,
            uiWording: 'n/a', // not stored on a draft; supplied only to satisfy the pipeline
        ))->html;
    }

    protected function find(string $key, string $locale): ?LegalDraft
    {
        return LegalDraft::query()->where('key', $key)->where('locale', $locale)->first();
    }

    /**
     * Insert or update, bumping `revision` with a SINGLE atomic statement rather than a
     * read-modify-write: `lockForUpdate()` compiles away on SQLite, so a read-then-increment
     * would silently race there while looking safe.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persist(string $key, string $locale, array $attributes, ?LegalDraft $existing): LegalDraft
    {
        if (! $existing instanceof LegalDraft) {
            try {
                // Saved through the model so the tenant stamp (BelongsToTenant's creating hook) lands.
                $draft = new LegalDraft;
                $draft->forceFill(array_merge([
                    'key' => $key,
                    'locale' => $locale,
                    'body' => '',
                    'content_hash' => $this->pipeline->hashOf(''),
                    'revision' => 1,
                ], $attributes));
                $draft->save();

                return $draft;
            } catch (UniqueConstraintViolationException) {
                // A concurrent writer (a queued translation racing the editor's sync save) inserted
                // this (key, locale, tenant) between the caller's read and this insert. The unique
                // index — not a lost update — is the arbiter: re-read the row that won and converge
                // to the update path below, instead of surfacing the violation to the caller.
                $existing = LegalDraft::query()->where('key', $key)->where('locale', $locale)->firstOrFail();
            }
        }

        // The query builder, not the model: `revision + 1` must be one atomic statement, and this
        // row is already identified by its primary key, so no scope is needed to find it.
        DB::table('legal_drafts')
            ->where('id', $existing->getKey())
            ->update(array_merge($attributes, [
                'revision' => DB::raw('revision + 1'),
                'updated_at' => now(),
            ]));

        return $existing->refresh();
    }

    private function sourceLocale(): string
    {
        $locale = config('legal-consent.default_locale');

        return is_string($locale) ? $locale : 'de';
    }
}
