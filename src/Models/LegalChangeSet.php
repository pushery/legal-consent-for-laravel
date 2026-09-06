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
     * The stored row as the freeze needs to see it: its state, and the columns the refusal names.
     *
     * ⚠️ NOT LOADED IS NOT "NOT PUBLISHED", and reading it that way opened the freeze on exactly
     * the rows it exists to protect. `getOriginal()` is `Arr::get($this->original, $key, $default)`
     * — on a partially hydrated row, `select(['id', …])`, the key is simply absent and the answer
     * is null. Null is not `Published`, so the write went through. Partial selects are house style
     * here, and on SQLite this hook IS the protection: the database triggers cover PostgreSQL and
     * MySQL only.
     *
     * So it asks the database rather than guessing — one read, only on the path that was wrong,
     * and it fetches what the MESSAGE needs too. Refusing without being able to say which document
     * was refused is its own kind of unhelpful.
     *
     * The comparison at the call site is against the enum alone: both branches here run the value
     * through the cast, so the backing string never arrives and a second arm for it would be a
     * branch no run can enter.
     *
     * Returns the MODEL whose original attributes are the stored row — this one when it loaded
     * them, a fresh read otherwise. Uniform on purpose: on a freshly fetched model nothing is
     * dirty, so `getOriginal()` and the attribute agree, and the caller needs only one accessor.
     *
     * @return self|null the stored row, or null when it cannot be found
     */
    private static function storedRow(self $set): ?self
    {
        if (array_key_exists('state', $set->getRawOriginal())) {
            return $set;
        }

        return self::query()->whereKey($set->getKey())->first(['state', 'key', 'locale', 'version']);
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
            $stored = self::storedRow($set);

            // A row that cannot be found is REFUSED, not waved through: a guard that cannot decide
            // must not decide in favor of the write.
            if ($stored instanceof LegalChangeSet && $stored->getOriginal('state') !== ChangeSetState::Published) {
                return;
            }

            $key = $stored?->getOriginal('key');
            $locale = $stored?->getOriginal('locale');
            $version = $stored?->getOriginal('version');

            throw LegalDocumentFrozenException::forChangeSet(
                is_string($key) ? $key : '?',
                is_string($locale) ? $locale : '?',
                is_string($version) ? $version : '',
            );
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
