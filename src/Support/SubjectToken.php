<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalNotice;

/**
 * The subject's stable pseudonym, shared by every ledger they appear in.
 *
 * A consent row and a notice-delivery row about the SAME subject must carry the SAME token, or the
 * two ledgers cannot be tied together once the account itself is gone — which is the only reason
 * the token exists: it is what keeps the proof meaningful once the subject reference is gone
 * (Art. 17(3)(b)/(e) — the proof survives the person).
 *
 * An Art. 17 erasure cannot simply clear the naming columns: that is an UPDATE, and both ledgers
 * refuse every UPDATE — the model blocks it, and PostgreSQL and MySQL each carry a BEFORE UPDATE
 * trigger. `Consent::forget()` therefore deletes each row and writes it again without the columns
 * that name the person, inside one transaction, re-linking the chain as it goes. The token is what
 * survives that: it is the only thing left tying a delivery proof to the consent it proves.
 *
 * Reuses the subject's existing token and mints one only when they have none yet. A write that
 * stores the token gets it from {@see whileLocked}, which holds one row per subject and tenant, or
 * two first writes at the same moment mint one each. Callers under multi-tenancy must run this
 * inside the right tenant (the lookup is tenant-scoped like every other LegalConsent read).
 */
final class SubjectToken
{
    /**
     * The registry: one row per subject and tenant, holding the subject's token. Migration 000037
     * creates it.
     */
    public const string REGISTRY = 'legal_subject_tokens';

    /**
     * How many times a write under {@see whileLocked} is tried when the database rolls it back as a
     * deadlock, the count every ledger write retries with.
     */
    private const int ATTEMPTS = 5;

    /**
     * Runs a write that stores the subject's token, hands it the token, and returns what the write
     * returns.
     *
     * Finding the token and storing the first row that carries it are two statements. Two first
     * writes for one subject at the same moment, a consent in a request and a notice in a queue
     * worker, each found no token and minted one of their own, which left the subject with two
     * chains that nothing ties together once an erasure has removed the subject reference.
     *
     * So the token has a row of its own in the registry, and every write takes that row first, in
     * the transaction it writes in. A second writer for the same subject waits there until the first
     * has committed. It then reads the token from the row with a locking read, which returns the
     * committed value even in a transaction whose snapshot is older, as a MySQL transaction's is once
     * it has read anything.
     *
     * The registry is the package's own rather than a lock on the subject's row, because a subject
     * need not have a row in this database at all. A deadlock is tried again, unless a transaction
     * of the caller's surrounds this one: the database has rolled that back as a whole. Where
     * migration 000037 has not run yet, the write gets its token as it did before, without the lock.
     *
     * @template TResult
     *
     * @param  Closure(string): TResult  $write
     * @return TResult
     */
    public function whileLocked(Model $subject, Closure $write): mixed
    {
        $connection = LegalConsent::resolve()->getConnection();

        // Settled before the transaction begins, so no read in it comes before the wait at the row.
        $keys = $this->registryKeys($subject);
        $registered = $keys !== null && $this->registered();

        return $connection->transaction(function () use ($connection, $subject, $keys, $registered, $write): mixed {
            if ($keys === null || ! $registered) {
                return $write($this->forSubject($subject));
            }

            [$lockKey, $subjectHash] = $keys;

            // An upsert, because it takes the row's exclusive lock at once, also where the row is
            // already there. An insert that ignores the duplicate takes a shared lock on MySQL, and
            // two writers upgrading theirs for the read below deadlock.
            $connection->table(self::REGISTRY)->upsert(
                [['lock_key' => $lockKey, 'subject_hash' => $subjectHash, 'subject_token' => null]],
                ['lock_key'],
                ['lock_key'],
            );

            $token = $connection->table(self::REGISTRY)->where('lock_key', $lockKey)->lockForUpdate()->value('subject_token');

            if (! is_string($token)) {
                // The subject's first write since the registry exists. The ledgers may hold a token
                // from before it, and forSubject() finds that one before it mints.
                $token = $this->forSubject($subject);

                $connection->table(self::REGISTRY)->where('lock_key', $lockKey)->update(['subject_token' => $token]);
            }

            return $write($token);
        }, self::ATTEMPTS);
    }

    /**
     * Whether the registry is there, which it is once migration 000037 has run.
     */
    public function registered(): bool
    {
        return LegalConsent::resolve()->getConnection()->getSchemaBuilder()->hasTable(self::REGISTRY);
    }

    /**
     * Takes the subject's rows in the registry for their erasure, in every tenant, so no write for
     * them runs until the erasure has committed.
     *
     * A write that is under way holds its row, so this waits for it to commit. A tenant whose
     * records predate the registry has no row yet, and a write there would not wait: it would read
     * the token from the records the erasure is about to strip and store it next to the subject
     * again, which ties the stripped records back to them. So a row is stored for such a tenant
     * here, without a token, and {@see forget} deletes it with the others.
     */
    public function hold(string $subjectType, string $subjectKey): void
    {
        $connection = LegalConsent::resolve()->getConnection();
        $subjectHash = $this->subjectHash($subjectType, $subjectKey);

        $held = $connection->table(self::REGISTRY)->where('subject_hash', $subjectHash)->lockForUpdate()->pluck('lock_key')->all();

        foreach (['legal_consents', 'legal_notices'] as $ledger) {
            $tenants = $connection->table($ledger)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectKey)
                ->distinct()
                ->pluck(TenantContext::COLUMN);

            foreach ($tenants as $tenant) {
                $lockKey = $this->lockKey(is_scalar($tenant) ? (string) $tenant : '', $subjectType, $subjectKey);

                if (! in_array($lockKey, $held, true)) {
                    $connection->table(self::REGISTRY)->upsert(
                        [['lock_key' => $lockKey, 'subject_hash' => $subjectHash, 'subject_token' => null]],
                        ['lock_key'],
                        ['lock_key'],
                    );

                    $held[] = $lockKey;
                }
            }
        }
    }

    /**
     * Deletes the subject's rows from the registry, in every tenant, as their erasure does with
     * everything else that names them.
     */
    public function forget(string $subjectType, string $subjectKey): void
    {
        LegalConsent::resolve()->getConnection()->table(self::REGISTRY)
            ->where('subject_hash', $this->subjectHash($subjectType, $subjectKey))
            ->delete();
    }

    /**
     * Deletes the rows whose token no ledger row carries any more, a page at a time, and returns how
     * many went. A subject whose last rows the retention sweep has pruned leaves such a row behind.
     */
    public function forgetUnused(int $page = 500): int
    {
        if (! $this->registered()) {
            return 0;
        }

        $connection = LegalConsent::resolve()->getConnection();

        $unused = static function (QueryBuilder $query): QueryBuilder {
            foreach (['legal_consents', 'legal_notices'] as $ledger) {
                $query->whereNotExists(static function (QueryBuilder $carried) use ($ledger): void {
                    $carried->selectRaw('1')->from($ledger)->whereColumn($ledger.'.subject_token', self::REGISTRY.'.subject_token');
                });
            }

            return $query;
        };

        $deleted = 0;

        // The page is found with a plain read and deleted by its keys, so a delete locks the rows of
        // one page rather than every row it passes. The rows are locked before they are asked about
        // again: a write for one of these subjects that is under way commits first, and the record
        // it stored keeps the row.
        $unused($connection->table(self::REGISTRY))->whereNotNull('subject_token')->select('lock_key')
            ->chunkById($page, function (Collection $rows) use ($connection, $unused, &$deleted): void {
                $keys = $rows->pluck('lock_key')->all();

                $deleted += $connection->transaction(function () use ($connection, $unused, $keys): int {
                    $connection->table(self::REGISTRY)->whereIn('lock_key', $keys)->lockForUpdate()->pluck('lock_key');

                    return $unused($connection->table(self::REGISTRY))->whereIn('lock_key', $keys)->delete();
                });
            }, 'lock_key');

        return $deleted;
    }

    /**
     * The two hashes a registry row names the subject by: of the tenant, the subject type and the
     * subject key, and of the type and key alone. Null for a subject without a key.
     *
     * @return array{0: string, 1: string}|null
     */
    private function registryKeys(Model $subject): ?array
    {
        $id = SubjectKey::for($subject);

        if ($id === null) {
            return null;
        }

        $type = (string) $subject->getMorphClass();

        return [$this->lockKey(app(TenantContext::class)->current(), $type, $id), $this->subjectHash($type, $id)];
    }

    private function lockKey(string $tenant, string $subjectType, string $subjectKey): string
    {
        return hash('sha256', implode("\0", [$tenant, $subjectType, $subjectKey]));
    }

    private function subjectHash(string $subjectType, string $subjectKey): string
    {
        return hash('sha256', $subjectType."\0".$subjectKey);
    }

    public function forSubject(Model $subject): string
    {
        // Both ledgers are searched, because either can be the first to token a subject. A change
        // is NOTICED before it is accepted, and a subject carried over by the v1 backfill has
        // consent rows with a null token — so the notice ledger mints first, and a consents-only
        // lookup would mint a SECOND token for the acceptance that follows. Two tokens for one
        // subject silently defeats the whole point: once an erasure has removed subject_id, the
        // delivery proof could no longer be tied to the consent it proves.
        $existing = $this->tokenIn(LegalConsent::model()::query(), $subject)
            ?? $this->tokenIn(LegalNotice::model()::query(), $subject);

        return $existing ?? (string) Str::uuid();
    }

    /**
     * The tokens for a WHOLE batch of subjects, resolved in one query per ledger per subject type
     * instead of two queries per subject, for a caller that resolves a page of subjects at once.
     * Same precedence as {@see forSubject}: an existing consent token wins, then a notice token,
     * else a freshly minted UUID. Keyed by "{morphClass}\0{key}" (see {@see mapKey}). Both ledgers
     * are read through the classes `legal-consent.models` maps, as every other path reads them.
     *
     * One query is not one ROW. A subject's ledger is append-only and holds one row per consent
     * action they have ever taken, all carrying the same token — so a lookup that does not collapse
     * them returns chunk-size x ledger-depth rows to arrive at chunk-size tokens, and the `??=` fold
     * below then discards every duplicate. `distinct()` collapses them in the engine instead, which
     * it can do exactly because the selection is already narrowed to the (subject_id, subject_token)
     * pair, and a caller resolving a page at a time is one that argues from a memory budget.
     *
     * @param  Collection<int, Model>  $subjects
     * @return array<string, string>
     */
    public function forSubjects(Collection $subjects): array
    {
        $resolved = [];

        // Consent tokens first (forSubject's precedence), then notice tokens for whoever is still
        // unresolved. `??=` keeps the first (consent) hit, so a later notice row never overrides it.
        foreach ([LegalConsent::model(), LegalNotice::model()] as $model) {
            // The cast is on the closure's own result, not decoration: a morph ALIAS may be
            // written as a number, and `getMorphClass()` then returns the int PHP made of that
            // array key. Without it the closure violates its own return type and the batch resolve
            // is fatal — before the line below, which exists for the same reason, is ever reached.
            foreach ($subjects->groupBy(static fn (Model $subject): string => (string) $subject->getMorphClass()) as $type => $group) {
                $type = (string) $type;
                $ids = $group->map(static fn (Model $subject): ?string => SubjectKey::for($subject))->all();

                $rows = $model::query()
                    ->distinct()
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
        return $this->pairKey((string) $subject->getMorphClass(), $subject->getKey());
    }

    /**
     * The map key for a (subject_type, subject_id) pair. The id cast lives on an assignment, not
     * inside the concat, so it satisfies PHPStan (a mixed id) without Rector stripping it as a
     * redundant concat autocast.
     */
    private function pairKey(string $type, mixed $id): string
    {
        $id = is_scalar($id) ? (string) $id : '';

        // The separator comes from {@see SubjectKey::pair()} rather than being spelled again: the
        // standing reader keys its map the same way, and two copies of a separator is how two maps
        // stop agreeing. The null POLICY stays this method's own — an unrepresentable id still
        // mints a token under the empty string here, where it would be absent there.
        return SubjectKey::pair($type, $id);
    }

    /**
     * @template TModel of LegalConsent|LegalNotice
     *
     * @param  Builder<TModel>  $query
     */
    private function tokenIn(Builder $query, Model $subject): ?string
    {
        $subjectId = SubjectKey::for($subject);

        // A subject without a key names nobody, and `subject_id = null` would hand it the token of a
        // row of its type that was written without a key.
        if ($subjectId === null) {
            return null;
        }

        $token = $query
            ->where('subject_type', (string) $subject->getMorphClass())
            ->where('subject_id', $subjectId)
            ->whereNotNull('subject_token')
            ->value('subject_token');

        return is_string($token) ? $token : null;
    }
}
