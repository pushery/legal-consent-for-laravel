<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;
use Pushery\LegalConsent\Enums\ChangeSetState;
use Pushery\LegalConsent\Exceptions\LegalDocumentFrozenException;
use Pushery\LegalConsent\Models\Concerns\BelongsToTenant;

/**
 * What one version of a legal text CHANGED, in the operator's own words, for one locale.
 *
 * A draft carries `version = ''` and is freely editable; publishing transitions the same row to the
 * version it describes and freezes it. Transition rather than copy, so the text a subject was shown
 * and the text on file cannot diverge.
 *
 * @property int $id
 * @property string $key
 * @property string $locale
 * @property string $tenant_id
 * @property string $version '' while it is the working draft
 * @property ChangeSetState $state
 * @property int|null $document_id
 * @property string|null $document_content_hash
 * @property string|null $headline
 * @property string|null $impact
 * @property string|null $source_fingerprint
 * @property string|null $change_hash
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class LegalChangeSet extends Model
{
    use BelongsToTenant;

    /** The `version` value that marks the one editable draft. Empty string, never NULL — see the migration. */
    public const string DRAFT_VERSION = '';

    /**
     * Nothing is mass-assignable. Once frozen this row is the record of what a subject was told, and
     * its writers are curated on purpose: the author, the freezer, and nothing else.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return HasMany<LegalChangeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(LegalChangeItem::class, 'change_set_id')->orderBy('position');
    }

    /**
     * @return BelongsTo<LegalDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'document_id');
    }

    /**
     * Does this set actually say anything? An empty description is not a description, and the
     * release pre-flight needs to tell "authored" from "row exists".
     *
     * Both fields, not either: § 327r Abs. 2 Satz 2 Nr. 1 BGB wants the characteristics of the
     * change and WP260 rev.01 Rz. 31 separately wants its likely impact.
     */
    public function isAuthored(): bool
    {
        return trim((string) $this->headline) !== '' && trim((string) $this->impact) !== '';
    }

    /**
     * Was this row already frozen before the write being attempted? The stored value may arrive as
     * the enum or as its backing string depending on how the row was loaded, so both are accepted.
     */
    private static function wasPublished(self $set): bool
    {
        $original = $set->getOriginal('state');

        return $original === ChangeSetState::Published || $original === ChangeSetState::Published->value;
    }

    #[Override]
    protected static function booted(): void
    {
        // The app-layer half of the freeze. The database triggers cover PostgreSQL and MySQL; this
        // covers SQLite and every path that goes through a model, and it produces a typed failure
        // instead of a raw SQLSTATE.
        self::updating(function (self $set): void {
            // getOriginal(), not the current attribute: the question is whether the row WAS frozen,
            // and reading the incoming value would let an update that also rewrites `state` walk
            // straight past the guard. The database triggers ask OLD.state for the same reason.
            if (! self::wasPublished($set)) {
                return;
            }

            $version = $set->getOriginal('version');

            throw LegalDocumentFrozenException::forChangeSet($set->key, $set->locale, is_string($version) ? $version : '');
        });

        self::deleting(function (self $set): void {
            if ($set->state->isFrozen()) {
                throw LegalDocumentFrozenException::forChangeSet($set->key, $set->locale, $set->version);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'state' => ChangeSetState::class,
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
