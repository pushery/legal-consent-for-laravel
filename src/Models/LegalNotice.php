<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Exceptions\LedgerImmutableException;
use Pushery\LegalConsent\Models\Concerns\BelongsToTenant;

/**
 * One append-only proof that a change notice was delivered to a subject on a durable
 * medium. Never updated: the model blocks UPDATE in every engine (Postgres + MySQL also
 * have a DB trigger). DELETE stays allowed so retention pruning can remove old rows.
 *
 * @property int $id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_token
 * @property string $tenant_id
 * @property int|null $document_id
 * @property string $document_key
 * @property string $document_version
 * @property int $document_major_version
 * @property string $locale
 * @property NoticeMode $notice_mode
 * @property string $medium
 * @property string $notice_body
 * @property string $notice_content_hash
 * @property bool $mandatory_content_ok
 * @property CarbonImmutable $sent_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $created_at
 */
final class LegalNotice extends Model
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
        self::creating(function (LegalNotice $notice): void {
            if ($notice->created_at === null) {
                $notice->created_at = CarbonImmutable::now();
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
            'notice_mode' => NoticeMode::class,
            'document_major_version' => 'integer',
            'mandatory_content_ok' => 'boolean',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
