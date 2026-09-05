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
use Pushery\LegalConsent\Support\DefaultConsentManager;

/**
 * One append-only ledger entry. Never updated: the model blocks UPDATE in every
 * engine (Postgres + MySQL also have a DB trigger). DELETE stays allowed so
 * retention/anonymization can prune old rows.
 *
 * @property int $id
 * @property string|null $subject_type
 * @property int|string|null $subject_id the subject's own primary key, held as a string column since 0.18 so a UUID or ULID key fits; it arrives as int from an in-memory model and as string from the database, and the proof chain casts both the same way
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
 * @property CarbonImmutable|null $subject_erased_at when an Art. 17 erasure rewrote this row without
 *                                                   its personal columns. Deliberately NOT a hashed
 *                                                   proof field (migration 000019): it is the trace
 *                                                   that a lawful rewrite happened, never a claim the
 *                                                   tamper chain vouches for — do not add it to
 *                                                   LedgerHashChain::PROOF_FIELDS
 * @property CarbonImmutable|null $created_at
 */
final class LegalConsent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * Nothing is mass-assignable. A consent row is court-proof evidence, and every legitimate write
     * goes through the curated attribute array in {@see DefaultConsentManager}
     * (forceCreate / forceFill). Blocking mass assignment makes "the manager is the only door"
     * structural: a stray LegalConsent::create($request->all()) can never forge or backdate a FRESH
     * proof row — subject_id, subject_token, prev_record_hash, accepted_at, content_hash. Mutation
     * AFTER insert is already refused by the append-only guard; this closes the insert side.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

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
            $consent->created_at ??= CarbonImmutable::now();
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
            'subject_erased_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
