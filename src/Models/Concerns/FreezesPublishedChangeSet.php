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
        // The app-layer half of the freeze, for an update and a delete alike. The database triggers
        // refuse both on every engine, with a raw SQLSTATE; this answers first, on every path that
        // goes through a model, with a typed refusal that names the document.
        //
        // It reads the stored row, never the attribute: the question is whether the row WAS frozen.
        // The attribute carries whatever the caller assigned, so a write that also rewrites `state`
        // would walk past the guard, and on a row loaded with a partial select it carries nothing at
        // all. The database triggers ask OLD.state for the same reason.
        $refuseWhileFrozen = static function (self $set): void {
            $stored = self::storedRow($set);
            $state = $stored?->getOriginal('state');

            // A row that cannot be found is refused, not waved through: a guard that cannot decide
            // must not decide in favor of the write.
            if ($stored instanceof self && (! $state instanceof ChangeSetState || ! $state->isFrozen())) {
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
        };

        static::updating($refuseWhileFrozen);
        static::deleting($refuseWhileFrozen);
    }
}
