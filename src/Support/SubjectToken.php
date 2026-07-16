<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalNotice;

/**
 * The subject's stable pseudonym, shared by every ledger they appear in.
 *
 * A consent row and a notice-delivery row about the SAME subject must carry the SAME token, or the
 * two ledgers cannot be tied together once the account itself is gone — which is the only reason
 * the token exists: it is what keeps the proof meaningful after an Art. 17 erasure nulls
 * subject_type/subject_id (Art. 17(3)(b)/(e) — the proof survives the person).
 *
 * Reuses the subject's existing token and mints one only when they have none yet. Callers under
 * multi-tenancy must run this inside the right tenant (the lookup is tenant-scoped like every
 * other LegalConsent read).
 */
final class SubjectToken
{
    public function forSubject(Model $subject): string
    {
        // Both ledgers are searched, because either can be the first to token a subject. A change
        // is NOTICED before it is accepted, and a subject carried over by the v1 backfill has
        // consent rows with a null token — so the notice ledger mints first, and a consents-only
        // lookup would mint a SECOND token for the acceptance that follows. Two tokens for one
        // subject silently defeats the whole point: after an erasure nulls subject_id, the
        // delivery proof could no longer be tied to the consent it proves.
        $existing = $this->tokenIn(LegalConsent::query(), $subject)
            ?? $this->tokenIn(LegalNotice::query(), $subject);

        return $existing ?? (string) Str::uuid();
    }

    /**
     * @template TModel of LegalConsent|LegalNotice
     *
     * @param  Builder<TModel>  $query
     */
    private function tokenIn(Builder $query, Model $subject): ?string
    {
        $token = $query
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->whereNotNull('subject_token')
            ->value('subject_token');

        return is_string($token) ? $token : null;
    }
}
