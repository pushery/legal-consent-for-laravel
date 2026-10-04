<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use WeakMap;

/**
 * Shared per-SUBJECT idempotency guard for the two registration-recording paths (Way A,
 * the Fortify trait; Way B, the Registered listener). Both may fire for the same account
 * in one request and must not double-write — but the 'request' singleton is process-shared,
 * so a global boolean would let a long-lived process (queue/console) firing Registered for
 * many users record only the first.
 *
 * The mark is held for the model INSTANCE, in a WeakMap on the request. The two paths of one
 * registration are handed the same instance, so they never both record it, and every other
 * subject records once. A registration whose transaction rolled back after the mark was set
 * removed the account and its consent rows, not the mark: a mark kept by the subject's key
 * would make a retry with the same key, which builds a new instance, create the account and
 * record nothing. An instance the process no longer holds drops out of the map by itself, so a
 * long-lived process does not collect marks either.
 */
final class RegistrationConsentDedup
{
    private const string ATTRIBUTE = 'legal_consent.recorded';

    /**
     * Returns true if this subject was already recorded on this request (skip); otherwise
     * marks it and returns false (proceed). Check-and-mark in one call so the two paths
     * can never both pass.
     */
    public static function seen(Request $request, Model $subject): bool
    {
        $recorded = $request->attributes->get(self::ATTRIBUTE);

        if (! $recorded instanceof WeakMap) {
            /** @var WeakMap<Model, true> $recorded */
            $recorded = new WeakMap;
            $request->attributes->set(self::ATTRIBUTE, $recorded);
        }

        if (isset($recorded[$subject])) {
            return true;
        }

        $recorded[$subject] = true;

        return false;
    }
}
