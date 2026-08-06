<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Pushery\LegalConsent\Enums\ChangeItemType;
use Pushery\LegalConsent\Enums\ChangeSetState;
use Pushery\LegalConsent\Exceptions\LegalDocumentFrozenException;

/**
 * One typed entry in a change description: a clause added, a processor removed, a right narrowed.
 *
 * `state` mirrors the parent so the database trigger can decide without a cross-table subquery,
 * which MySQL refuses inside a BEFORE trigger. The invariant that the two agree is asserted by a
 * test rather than assumed.
 *
 * @property int $id
 * @property int $change_set_id
 * @property ChangeSetState $state
 * @property int $position
 * @property ChangeItemType $type
 * @property string $subject
 * @property string|null $detail
 * @property string|null $party_name
 * @property string|null $party_location
 * @property string|null $party_contact
 * @property string|null $purpose
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class LegalChangeItem extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return BelongsTo<LegalChangeSet, $this>
     */
    public function changeSet(): BelongsTo
    {
        return $this->belongsTo(LegalChangeSet::class, 'change_set_id');
    }

    /**
     * Does this entry carry the facets EDPB Opinion 22/2024 Rz. 22 expects when a new party starts
     * handling personal data — who they are, where the data goes, and what they do with it?
     *
     * A contact address is deliberately not required: the opinion lists it, but an operator who
     * names a processor and its jurisdiction has disclosed the substance, and refusing the notice
     * over a missing mailbox would withhold a notice over a formality.
     */
    public function describesPartyFully(): bool
    {
        return trim((string) $this->party_name) !== ''
            && trim((string) $this->party_location) !== ''
            && trim((string) $this->purpose) !== '';
    }

    #[Override]
    protected static function booted(): void
    {
        self::updating(function (self $item): void {
            $original = $item->getOriginal('state');

            if ($original === ChangeSetState::Published->value || $original === ChangeSetState::Published) {
                throw LegalDocumentFrozenException::forChangeItem($item->change_set_id, $item->position);
            }
        });

        self::deleting(function (self $item): void {
            if ($item->state->isFrozen()) {
                throw LegalDocumentFrozenException::forChangeItem($item->change_set_id, $item->position);
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
            'type' => ChangeItemType::class,
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
