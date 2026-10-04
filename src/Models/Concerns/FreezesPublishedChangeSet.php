<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models\Concerns;

use Pushery\LegalConsent\Enums\ChangeSetState;
use Pushery\LegalConsent\Exceptions\LegalDocumentFrozenException;

/**
 * A published change set is frozen with its version: an update or a delete is refused with a
 * typed exception.
 *
 * A trait's boot method rather than `booted()`, so the guard holds for a host's own subclass too:
 * Laravel boots the traits of the whole class hierarchy, and calls only the concrete class's
 * `booted()`, which a subclass replaces when it declares its own without calling its parent.
 */
trait FreezesPublishedChangeSet
{
    public static function bootFreezesPublishedChangeSet(): void
    {
        // The app-layer half of the freeze. The database triggers cover PostgreSQL and MySQL; this
        // covers SQLite and every path that goes through a model, and it produces a typed failure
        // instead of a raw SQLSTATE.
        static::updating(function (self $set): void {
            // getOriginal(), not the current attribute: the question is whether the row WAS frozen,
            // and reading the incoming value would let an update that also rewrites `state` walk
            // straight past the guard. The database triggers ask OLD.state for the same reason.
            $stored = self::storedRow($set);

            // A row that cannot be found is REFUSED, not waved through: a guard that cannot decide
            // must not decide in favor of the write.
            if ($stored instanceof self && $stored->getOriginal('state') !== ChangeSetState::Published) {
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

        static::deleting(function (self $set): void {
            if ($set->state->isFrozen()) {
                throw LegalDocumentFrozenException::forChangeSet($set->key, $set->locale, $set->version);
            }
        });
    }
}
