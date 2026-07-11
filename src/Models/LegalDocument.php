<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Override;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Models\Concerns\BelongsToTenant;

/**
 * One frozen, published version of a legal text.
 *
 * @property int $id
 * @property string $key
 * @property DocumentType $type
 * @property bool $requires_explicit_optin
 * @property string $locale
 * @property string $tenant_id
 * @property string $title
 * @property string $version
 * @property int $major_version
 * @property int $minor_version
 * @property int $patch_version
 * @property string $content_format
 * @property string $content
 * @property string $content_hash
 * @property string $ui_wording
 * @property string $source_driver
 * @property string|null $source_reference
 * @property bool $requires_reconsent
 * @property string|null $change_summary
 * @property bool $is_active
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $announce_from
 * @property CarbonImmutable|null $enforce_from
 * @property CarbonImmutable|null $notified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<LegalDocument> active()
 */
final class LegalDocument extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['content'];

    /**
     * @return HasMany<LegalConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(LegalConsent::class, 'document_id');
    }

    /**
     * Only the active version of a (key, locale).
     *
     * @param  Builder<LegalDocument>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * A mandatory, active version whose enforcement window has opened.
     */
    public function isEnforceable(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->is_active
            && ! $this->requires_explicit_optin
            && $this->enforce_from instanceof CarbonImmutable
            && $this->enforce_from->lessThanOrEqualTo($now);
    }

    /**
     * Make this the single active version of its (key, locale). Portable guard for
     * "one active version" on every engine; Postgres also has a partial unique index.
     */
    public function activate(): void
    {
        DB::transaction(function (): void {
            self::query()
                ->where('key', $this->key)
                ->where('locale', $this->locale)
                ->whereKeyNot($this->getKey())
                ->update(['is_active' => false]);

            $this->forceFill(['is_active' => true])->save();
        });
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'requires_explicit_optin' => 'boolean',
            'major_version' => 'integer',
            'minor_version' => 'integer',
            'patch_version' => 'integer',
            'requires_reconsent' => 'boolean',
            'is_active' => 'boolean',
            'published_at' => 'immutable_datetime',
            'announce_from' => 'immutable_datetime',
            'enforce_from' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
