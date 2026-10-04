<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models\Concerns;

use Pushery\LegalConsent\Enums\ChangeSetState;
use Pushery\LegalConsent\Exceptions\LegalDocumentFrozenException;

/**
 * A published change item is frozen with its change set: an update or a delete is refused with a
 * typed exception.
 *
 * A trait's boot method rather than `booted()`, so the guard holds for a host's own subclass too:
 * Laravel boots the traits of the whole class hierarchy, and calls only the concrete class's
 * `booted()`, which a subclass replaces when it declares its own without calling its parent.
 */
trait FreezesPublishedChangeItem
{
    public static function bootFreezesPublishedChangeItem(): void
    {
        static::updating(function (self $item): void {
            $stored = self::storedRow($item);

            // A row that cannot be found is REFUSED, not waved through.
            if ($stored instanceof self && $stored->getOriginal('state') !== ChangeSetState::Published) {
                return;
            }

            $changeSetId = $stored?->getOriginal('change_set_id');
            $position = $stored?->getOriginal('position');

            throw LegalDocumentFrozenException::forChangeItem(
                is_int($changeSetId) ? $changeSetId : 0,
                is_int($position) ? $position : 0,
            );
        });

        static::deleting(function (self $item): void {
            if ($item->state->isFrozen()) {
                throw LegalDocumentFrozenException::forChangeItem($item->change_set_id, $item->position);
            }
        });
    }
}
