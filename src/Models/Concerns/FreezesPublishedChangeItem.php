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
        // One check for an update and a delete, reading the stored row rather than the attribute,
        // for the reasons FreezesPublishedChangeSet gives.
        $refuseWhileFrozen = static function (self $item): void {
            $stored = self::storedRow($item);
            $state = $stored?->getOriginal('state');

            // A row that cannot be found is refused, not waved through.
            if ($stored instanceof self && (! $state instanceof ChangeSetState || ! $state->isFrozen())) {
                return;
            }

            $changeSetId = $stored?->getOriginal('change_set_id');
            $position = $stored?->getOriginal('position');

            throw LegalDocumentFrozenException::forChangeItem(
                is_int($changeSetId) ? $changeSetId : 0,
                is_int($position) ? $position : 0,
            );
        };

        static::updating($refuseWhileFrozen);
        static::deleting($refuseWhileFrozen);
    }
}
