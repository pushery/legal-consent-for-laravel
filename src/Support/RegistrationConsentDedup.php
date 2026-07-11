<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared per-SUBJECT idempotency guard for the two registration-recording paths (Way A,
 * the Fortify trait; Way B, the Registered listener). Both may fire for the same account
 * in one request and must not double-write — but the 'request' singleton is process-shared,
 * so a global boolean would let a long-lived process (queue/console) firing Registered for
 * many users record only the first. Keying the marker by the subject's identity blocks a
 * same-request double-write while letting every distinct subject record exactly once.
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
        $key = $subject->getKey();
        $suffix = is_int($key) || is_string($key) ? (string) $key : '';
        $identifier = $subject->getMorphClass().':'.$suffix;

        $recorded = $request->attributes->get(self::ATTRIBUTE, []);
        $recorded = is_array($recorded) ? $recorded : [];

        if (in_array($identifier, $recorded, true)) {
            return true;
        }

        $recorded[] = $identifier;
        $request->attributes->set(self::ATTRIBUTE, $recorded);

        return false;
    }
}
