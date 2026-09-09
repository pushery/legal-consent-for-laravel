<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * The one place that takes the lock every writer of a document's active version queues on.
 *
 * Three call sites take it — {@see LegalDocumentReleaser::release()} across a multi-locale
 * release, {@see LegalDocumentPublisher::publishWithMode()} across a single publish, and
 * {@see LegalDocument::activateSerialized()} across a bare activation. Each of them used to
 * carry its own copy of the same store predicate, its own `10`, its own `5` and its own warning:
 * four constants and one condition, written three times, for one question.
 *
 * ⚠️ THAT TRIPLICATION IS NOT A TIDINESS COMPLAINT — IT SHIPPED A DEADLOCK. A release took the
 * lock and then called the publisher, which took the SAME NAME again inside it.
 * A Laravel lock is not reentrant: the second instance carries a different owner token, waits its
 * full five seconds and throws, so every release on a real store failed on its own serialization.
 * Both sites guarded the take with the same predicate spelled two ways — `! LockProvider ||
 * Array || Null` in one, `LockProvider && ! Array && ! Null` in the other — so they were never
 * out of step about WHETHER to lock, only about who already had. Two spellings of one rule is
 * how a reader checks one and believes both.
 *
 * @internal This class is an extraction of logic that already lived in its three callers, not a
 * capability offered to consuming applications. It carries no backward-compatibility promise and
 * may change shape in any release. Saying so is what keeps a bug fix a PATCH: a new type in the
 * shipped tree would otherwise read as an addition to the supported surface, and the repair of a
 * defect would owe consumers a minor bump for something none of them can use.
 */
final class ActivationLock
{
    /**
     * Seconds the holder may keep the lock. A crashed holder must not wedge every later writer,
     * and no activation legitimately runs longer than this.
     */
    public const int TTL_SECONDS = 10;

    /** Seconds a waiting writer blocks before giving up and throwing LockTimeoutException. */
    public const int WAIT_SECONDS = 5;

    /**
     * Whether an orchestrating caller already owns the lock, so an inner writer must not take it.
     *
     * WHOEVER OPENS THE TRANSACTION OWNS THE LOCK. That rule is the package's, not Laravel's, and
     * it is load-bearing in both directions. A lock taken inside somebody else's transaction is
     * released when the inner method returns — BEFORE that caller's commit — so it never spans the
     * write it appears to protect: false comfort, not serialization. And re-taking a name this
     * call stack already holds cannot succeed at all, because the new instance is a different
     * owner; it can only wait out {@see WAIT_SECONDS} and throw.
     *
     * The transaction is the signal because the orchestrator is the thing that opens one:
     * {@see LegalDocumentReleaser} holds this lock across the transaction that spans every locale
     * of a release, which is exactly the span an inner lock could not have covered.
     */
    public static function ownedByCaller(): bool
    {
        return DB::transactionLevel() > 0;
    }

    /**
     * Run $work serialized, UNLESS an orchestrating caller already holds this lock.
     *
     * The entry point for a nested writer — the publisher inside a release, an activation inside a
     * publish. Taking the lock there is not merely redundant, it is fatal: see
     * {@see ownedByCaller()} for why a second instance of a non-reentrant lock can only time out.
     *
     * The branch lives HERE rather than at each call site on purpose. A transactional test suite
     * has a transaction open for every test that has touched the database, so a caller-side
     * `ownedByCaller() ? … : …` would have one arm no test in this suite can enter — and an
     * unreachable arm is where a serialization quietly stops happening. In here both arms are
     * reachable, because a closure that touches nothing leaves the suite at transaction level 0.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     */
    public static function serializeUnlessOwned(string $key, string $subject, Closure $work): mixed
    {
        return self::ownedByCaller() ? $work() : self::serialize($key, $subject, $work);
    }

    /**
     * Run $work serialized against every other writer of $key's active version.
     *
     * Degrades LOUDLY rather than throwing when the configured store cannot serialize: a store
     * with no LockProvider (session, storage, apc, a custom one) would otherwise make publishing
     * FATAL, and `array` and `null` DO implement LockProvider while serializing nothing across
     * processes — array is process-local, a null lock always succeeds. Those two are the shapes
     * worth naming, because they are the ones that LOOK protected. Either way the work still runs;
     * every engine now carries a partial unique index on the active row, so the database remains
     * the real guarantee and this lock is defense in depth over it.
     *
     * $subject names the act in the warning ('a release', 'a publish', 'activation') so an
     * operator reading the log learns which writer degraded, not merely that one did.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     */
    public static function serialize(string $key, string $subject, Closure $work): mixed
    {
        $store = LegalDocument::activationLockStore();

        if (! $store instanceof LockProvider || $store instanceof ArrayStore || $store instanceof NullStore) {
            Log::warning(sprintf('legal-consent: cache store cannot serialize %s, running unserialized', $subject), [
                'document_key' => $key,
                'store' => $store::class,
            ]);

            return $work();
        }

        // The annotation carries what the signature cannot: `block()` returns whatever its
        // callback returns and is typed `mixed`, while the callers declare a real return type.
        /** @var TReturn $result */
        $result = $store->lock(LegalDocument::activationLockName($key), self::TTL_SECONDS)
            ->block(self::WAIT_SECONDS, $work);

        return $result;
    }
}
