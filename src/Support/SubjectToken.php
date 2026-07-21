<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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
     * The tokens for a WHOLE batch of subjects, resolved in one query per ledger per subject type
     * instead of two queries per subject — the notice sweep resolves 500 at a time. Same precedence
     * as {@see forSubject}: an existing consent token wins, then a notice token, else a freshly
     * minted UUID. Keyed by "{morphClass}\0{key}" (see {@see mapKey}).
     *
     * @param  Collection<int, Model>  $subjects
     * @return array<string, string>
     */
    public function forSubjects(Collection $subjects): array
    {
        $resolved = [];

        // Consent tokens first (forSubject's precedence), then notice tokens for whoever is still
        // unresolved. `??=` keeps the first (consent) hit, so a later notice row never overrides it.
        foreach ([LegalConsent::class, LegalNotice::class] as $model) {
            foreach ($subjects->groupBy(static fn (Model $subject): string => $subject->getMorphClass()) as $type => $group) {
                $type = (string) $type;
                $ids = $group->map(static fn (Model $subject): mixed => $subject->getKey())->all();

                $rows = $model::query()
                    ->where('subject_type', $type)
                    ->whereIn('subject_id', $ids)
                    ->whereNotNull('subject_token')
                    ->get(['subject_id', 'subject_token']);

                foreach ($rows as $row) {
                    $resolved[$this->pairKey($type, $row->subject_id)] ??= (string) $row->subject_token;
                }
            }
        }

        // Mint for any subject still tokened in neither ledger.
        foreach ($subjects as $subject) {
            $resolved[$this->mapKey($subject)] ??= (string) Str::uuid();
        }

        return $resolved;
    }

    /**
     * The lookup key for {@see forSubjects}' map — a NUL byte cannot appear in a morph alias or key,
     * so the pair never collides.
     */
    public function mapKey(Model $subject): string
    {
        return $this->pairKey($subject->getMorphClass(), $subject->getKey());
    }

    /**
     * The map key for a (subject_type, subject_id) pair. The id cast lives on an assignment, not
     * inside the concat, so it satisfies PHPStan (a mixed id) without Rector stripping it as a
     * redundant concat autocast.
     */
    private function pairKey(string $type, mixed $id): string
    {
        $id = is_scalar($id) ? (string) $id : '';

        return $type."\0".$id;
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
