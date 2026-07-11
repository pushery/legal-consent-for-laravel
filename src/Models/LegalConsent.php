<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Exceptions\LedgerImmutableException;
use Pushery\LegalConsent\Models\Concerns\BelongsToTenant;

/**
 * One append-only ledger entry. Never updated: the model blocks UPDATE in every
 * engine (Postgres + MySQL also have a DB trigger). DELETE stays allowed so
 * retention/anonymization can prune old rows.
 *
 * @property int $id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_token
 * @property int|null $document_id
 * @property string $document_key
 * @property DocumentType $document_type
 * @property string $document_version
 * @property int $document_major_version
 * @property string $content_hash
 * @property string $locale
 * @property string $ui_wording_snapshot
 * @property ConsentAction $action
 * @property ConsentMethod $method
 * @property string|null $source
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property string|null $prev_record_hash
 * @property CarbonImmutable $accepted_at
 * @property CarbonImmutable|null $created_at
 */
final class LegalConsent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<LegalDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'document_id');
    }

    #[Override]
    protected static function booted(): void
    {
        self::creating(function (LegalConsent $consent): void {
            if ($consent->created_at === null) {
                $consent->created_at = CarbonImmutable::now();
            }
        });

        self::updating(function (): never {
            throw LedgerImmutableException::onUpdate();
        });
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'document_major_version' => 'integer',
            'action' => ConsentAction::class,
            'method' => ConsentMethod::class,
            'accepted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
