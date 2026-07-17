<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;
use Pushery\LegalConsent\Enums\DraftOrigin;
use Pushery\LegalConsent\Enums\ReviewState;
use Pushery\LegalConsent\Models\Concerns\BelongsToTenant;
use Pushery\LegalConsent\Support\LegalDraftSet;

/**
 * The editable working copy of one legal text in one locale — the input a publish freezes.
 *
 * Mutable by design, unlike its published counterpart {@see LegalDocument}: a draft is where the
 * text is worked on, a published row is frozen proof. Carries no staleness flag — staleness is a
 * relation between this row and the source-locale row, derived on read (see
 * {@see LegalDraftSet}), never a stored boolean that can drift.
 *
 * @property int $id
 * @property string $key
 * @property string $locale
 * @property string $tenant_id
 * @property string $body
 * @property string $content_hash
 * @property string|null $source_hash
 * @property string|null $version
 * @property ReviewState $review_state
 * @property DraftOrigin $origin
 * @property int $revision
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class LegalDraft extends Model
{
    use BelongsToTenant;

    /**
     * Nothing is mass-assignable. `body` is the sole input to the editor's raw-HTML `{!! !!}`
     * preview, and the sanitize-on-store invariant lives entirely in {@see LegalDraftWriter} (which
     * writes via forceFill / DB::table, bypassing this guard). Blocking mass-assignment makes "the
     * writer is the only door" structural: a stray LegalDraft::create($input) / ->fill($input) can
     * never land an unsanitized body straight into the preview sink.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'review_state' => ReviewState::class,
            'origin' => DraftOrigin::class,
            'revision' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
