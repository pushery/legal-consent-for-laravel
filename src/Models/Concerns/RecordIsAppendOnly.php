<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models\Concerns;

use Carbon\CarbonImmutable;
use Pushery\LegalConsent\Exceptions\LedgerImmutableException;

/**
 * A ledger row is written once and never changed: an insert gets its `created_at`, and an update
 * is refused with a typed exception before any SQL is issued.
 *
 * A trait's boot method rather than `booted()`, so the guard holds for a host's own subclass too:
 * Laravel boots the traits of the whole class hierarchy, and calls only the concrete class's
 * `booted()`, which a subclass replaces when it declares its own without calling its parent.
 */
trait RecordIsAppendOnly
{
    public static function bootRecordIsAppendOnly(): void
    {
        static::creating(function (self $row): void {
            $row->created_at ??= CarbonImmutable::now();
        });

        static::updating(function (): never {
            throw LedgerImmutableException::onUpdate();
        });
    }
}
