<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Strip a subject from both proof ledgers under Art. 17, and leave the tamper chain verifiable.
 *
 * WHY IT IS NOT AN UPDATE. Both ledgers refuse every UPDATE — the model blocks it and PostgreSQL
 * and MySQL each carry a BEFORE UPDATE trigger — so clearing the personal columns in place is not
 * available and nothing here relaxes that. `DELETE` and `INSERT` are both allowed (the create
 * migration says so outright), so a row is removed and written again without the columns.
 *
 * WHY IT RE-CHAINS, which is the part a consumer cannot get right on its own. The obvious version
 * — delete the row, insert it back carrying the same `prev_record_hash` — was measured and it
 * breaks the chain twice over:
 *
 *  1. `subject_type`, `subject_id`, `ip_address`, `user_agent` and `request_id` are all inputs to
 *     `LedgerHashChain::canonical()`. There is no subset of the personal data outside the hash, so
 *     erasing any of it necessarily changes the row's own hash and every successor's stored link
 *     points at a value that no longer exists.
 *  2. A row's hash folds in its OWN `prev_record_hash`, so fixing one link changes the next one's
 *     input. It is a cascade, not a single correction.
 *
 * So the whole of the subject's chain is rewritten in order, each row linked to the one before it
 * as stored. Every rewritten row records `subject_erased_at`: a lawful change to append-only
 * evidence that leaves no trace is indistinguishable from the tampering the chain exists to catch.
 *
 * IDS ARE PRESERVED, AND THAT IS LOAD-BEARING RATHER THAN TIDY. The verifier flags any row with
 * a NULL link whose id is past the FIRST CHAINED ROW IN THE WHOLE TABLE — that watermark is global.
 * A subject with pre-tamper-evidence rows re-inserted at fresh ids would land every one of them
 * past it and be reported as a direct database write. Reusing the original ids keeps both that
 * watermark and the walk's ordering exactly as they were.
 *
 * WHAT SURVIVES: the document, its type, version, major version, content hash, locale, the frozen
 * wording, the action, the method, the instant — and `subject_token`, the pseudonym that still
 * ties the two ledgers together afterwards. What the proof needs, none of what identifies a person.
 */
final readonly class LedgerSubjectEraser
{
    /**
     * Cleared on a consent row.
     *
     * `request_id` belongs here and is easy to leave behind: it is the key to the server logs that
     * hold the same address, and removing the lock while leaving the key is the same disclosure one
     * query further out.
     */
    private const array CONSENT_PERSONAL_COLUMNS = [
        'subject_type', 'subject_id', 'ip_address', 'user_agent', 'request_id',
    ];

    /**
     * Cleared on a notice row — a SHORTER list, and the difference is measured rather than assumed.
     *
     * That ledger has no `ip_address`, `user_agent` or `request_id` at all; clearing them would
     * write columns the table does not have.
     *
     * `notice_body` STAYS, and it is the one that looks like it should go. It holds the exact
     * message served, which reads like per-person data. It is not — but the reason is narrower than
     * it used to say here, and the old wording pointed at a method that does not exist.
     *
     * `WriteNoticeDeliveryProof::render()` IS handed the subject, and passes it to
     * `toMail($notifiable)`. What makes the body the same for everyone is that the three shipped
     * notifications build it from the DOCUMENT alone — title, dates, change items, the § 308 Nr. 5
     * lit. b warning — and never read the notifiable. So the guarantee lives in those classes, not
     * in a signature, and a consumer who subclasses `ChangeNotification` and renders per subject
     * breaks it. That is the case this list has to change for, and it is a seam a consumer is
     * invited to use.
     *
     * Clearing it would destroy the durable-medium proof of what was communicated — the thing that
     * makes a deemed acceptance binding at all (§ 308 Nr. 5 lit. b) — and, as the code stands,
     * remove nothing about the person.
     */
    private const array NOTICE_PERSONAL_COLUMNS = ['subject_type', 'subject_id'];

    /**
     * How many times an erasure is tried when the database rolls it back as a deadlock, the count
     * every ledger write retries with.
     */
    private const int ATTEMPTS = 5;

    public function __construct(private LedgerChainRepair $repair = new LedgerChainRepair) {}

    public function forget(Model $subject): SubjectErasure
    {
        $type = (string) $subject->getMorphClass();
        $id = SubjectKey::for($subject);

        if ($id === null) {
            return new SubjectErasure;
        }

        // Asked before the transaction begins. On MySQL a transaction reads from the snapshot of its
        // first read, and the registry rows below are taken before any.
        $tokens = new SubjectToken;
        $registered = $tokens->registered();

        return DB::transaction(function () use ($type, $id, $tokens, $registered): SubjectErasure {
            // First: a write for the subject that is under way holds their registry row, so this
            // waits for it to commit, and no write for them starts until this one has committed.
            // The ledgers are read with locking reads below, which see what such a write stored.
            if ($registered) {
                $tokens->hold($type, $id);
            }

            $erasedAt = CarbonImmutable::now();

            [$consents, $rechained, $unverified] = $this->eraseConsents($type, $id, $erasedAt);

            // Operational state, not evidence: which notices were on their way to this subject and
            // which failed. It names the subject and nothing needs it once they are gone.
            NoticeAttempts::forgetSubject($type, $id);

            $notices = $this->eraseNotices($type, $id, $erasedAt);

            // The rows tie the subject to their token, the tie the erasure exists to cut.
            if ($registered) {
                $tokens->forget($type, $id);
            }

            return new SubjectErasure(
                consents: $consents,
                notices: $notices,
                rechained: $rechained,
                unverifiedChains: $unverified,
            );
        }, self::ATTEMPTS);
    }

    /**
     * The consent ledger, which is the one that carries a chain.
     *
     * A chain that no longer verifies as this package wrote it is erased like any other and NOT
     * re-linked: re-linking recomputes every link with the key and records fresh macs, which would
     * give rows written through SQL the key's approval. Its links stay as they are, so the verifier
     * goes on reporting what changed it, and the count goes back to the caller.
     *
     * @return array{0: int, 1: int, 2: int} rows erased, rows re-linked, chains left unverified
     */
    private function eraseConsents(string $type, string $id, CarbonImmutable $erasedAt): array
    {
        // Every row this subject's chain touches, not only the rows still naming them. A previous
        // erasure leaves rows with a null subject and the same token, and their hashes are inputs
        // to the links of everything after them — walking only the still-named rows would rebuild
        // half a chain onto the other half's stale values.
        //
        // Read with locking reads, until two reads agree ({@see LockedRows}): a row written while
        // the erasure reads would keep naming the subject, and link to a hash the erasure changes.
        // The subject's rows and their chains are asked for separately, each on its own index, so
        // the locks stay on those rows rather than on whatever a combined condition scans.
        $rows = LockedRows::settled(static function () use ($type, $id): array {
            $named = DB::table('legal_consents')
                ->where('subject_type', $type)
                ->where('subject_id', $id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $tokens = $named->pluck('subject_token')->filter(static fn (mixed $token): bool => is_string($token))->unique()->values()->all();

            if ($tokens === []) {
                return array_values($named->all());
            }

            $chained = DB::table('legal_consents')
                ->whereIn('subject_token', $tokens)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            return array_values($named->merge($chained)->unique('id')->sortBy('id')->all());
        });

        if ($rows === []) {
            return [0, 0, 0];
        }

        // Checked on the rows as read, before a column is cleared: clearing changes every hash, so
        // afterwards there is nothing left to check against.
        $unverified = $this->unverifiedTokens($rows);

        $erased = 0;
        $rewritten = [];

        foreach ($rows as $row) {
            $attributes = $this->repair->toRow($row);

            if ($attributes['subject_id'] !== null || $attributes['subject_type'] !== null) {
                foreach (self::CONSENT_PERSONAL_COLUMNS as $column) {
                    $attributes[$column] = null;
                }

                $attributes['subject_erased_at'] = $erasedAt;
                $erased++;
            }

            $rewritten[] = $attributes;
        }

        // The link walk lives in one place for both callers — here and the retention sweep — and
        // it runs AFTER the columns are cleared, because those columns are inputs to every hash
        // it computes. Doing it the other way round would link each row to a value that the very
        // next statement invalidates.
        $verified = array_values(array_filter(
            $rewritten,
            static fn (array $row): bool => ! isset($unverified[is_string($row['subject_token'] ?? null) ? $row['subject_token'] : '']),
        ));

        $rechained = $this->repair->chainedCount($verified);
        $read = $rewritten;
        $rewritten = $this->relinkPerToken($rewritten, $unverified);

        // The writer stamps the chain-root boundary before the first root proof it writes, and the
        // re-link gives the opener of a keyed chain one where it had none.
        if ($this->repair->writesARootProof($read, $rewritten)) {
            (new LedgerHashChain)->stampRootBoundary();
        }

        // Delete before insert, in one transaction, so the unique chain-link index from 000012 is
        // never asked to hold two rows with the same (token, prev_record_hash) at once.
        DB::table('legal_consents')->whereIn('id', array_column($rewritten, 'id'))->delete();

        $this->insertRows('legal_consents', $rewritten);

        // The erasure cleared personal columns and the re-link moved every chain link, so every
        // one of these rows hashes to something new — including the chain TAIL, the row a mac is
        // the only witness for. Recording the new macs is what keeps a lawful Art. 17 erasure from
        // reading as a replacement, the same reason the links are repaired at all.
        //
        // The ids are read from the rewritten rows rather than kept from before: a rewrite
        // preserves `id` by contract ({@see LedgerHashChain::UNHASHED_COLUMNS}), and reading them
        // from what was actually written is what holds that contract rather than assuming it.
        //
        // Not for a chain left unverified: its rows keep the links they had, and a fresh mac would
        // vouch for them.
        //
        // The macs recorded before go first, for every rewritten row. Each is a hash over the row
        // as it was, personal data included, and unkeyed it can be reversed by trying subject ids
        // against it: kept, it would undo the erasure it sits next to.
        $macs = new LedgerRecordMacs;
        $macs->forget(array_column($rewritten, 'id'));
        $macs->record(array_column($verified, 'id'));

        return [$erased, $rechained, count($unverified)];
    }

    /**
     * The tokens among these rows whose chain does not verify as this package wrote it.
     *
     * @param  array<int, stdClass>  $rows
     * @return array<string, true>
     */
    private function unverifiedTokens(array $rows): array
    {
        $byToken = [];

        foreach ($rows as $row) {
            if (is_string($row->subject_token ?? null) && $row->subject_token !== '') {
                $byToken[$row->subject_token][] = $row;
            }
        }

        $soundness = new LedgerChainSoundness;
        $unverified = [];

        foreach ($byToken as $token => $group) {
            if (! $soundness->sound($group)) {
                $unverified[$token] = true;
            }
        }

        return $unverified;
    }

    /**
     * Re-link each `subject_token`'s rows on its OWN chain.
     *
     * {@see LedgerChainRepair::relink()} carries one link pointer across the whole array it is
     * handed, and its contract says what that array is: one subject_token's rows, in id order. A
     * subject can hold MORE than one token — {@see SubjectToken} mints on (subject_type,
     * subject_id) with no uniqueness, so two concurrent first writers (a notice sweep and a
     * consent write) mint two, which is the state `verify-ledger` has a dedicated check for.
     * Handing both sets over in one call chained the second token's first row onto the first
     * token's last one, and the verifier — which restarts at genesis for every token — then
     * reported "chain does not start at genesis" permanently, on a ledger the lawful erasure had
     * just broken itself. The retention sweep loops per token for exactly this reason.
     *
     * Rows with no token are left exactly as they are: the walk never reaches them, so giving one
     * a link would invent a chain rather than repair one.
     *
     * Every row that goes in comes back out, repaired or untouched — the walk replaces entries and
     * never adds or drops one. The signature says so on both sides, which is what lets the caller
     * hand the result straight to {@see insertRows()} without proving all over again that a
     * non-empty read of the ledger is still non-empty.
     *
     * A token in `$unverified` is left as it is, see {@see eraseConsents()}.
     *
     * @param  non-empty-list<array<string, mixed>>  $rows  in id order
     * @param  array<string, true>  $unverified
     * @return non-empty-list<array<string, mixed>>
     */
    private function relinkPerToken(array $rows, array $unverified = []): array
    {
        /** @var array<string, array<int, array<string, mixed>>> $groups */
        $groups = [];

        foreach ($rows as $index => $row) {
            $token = $row['subject_token'] ?? null;

            if (is_string($token) && $token !== '' && ! isset($unverified[$token])) {
                $groups[$token][$index] = $row;
            }
        }

        foreach ($groups as $group) {
            $positions = array_keys($group);
            $corrected = $this->repair->relink(array_values($group));

            foreach ($positions as $offset => $index) {
                $rows[$index] = $corrected[$offset];
            }
        }

        return $rows;
    }

    /**
     * Write the rewritten rows back in as few statements as the driver's placeholder budget allows.
     *
     * One INSERT per row is one network round trip per row, and on a remote (serverless) database
     * the latency is the whole cost of an erasure. The rows are already complete, uniform attribute
     * arrays, so a multi-row INSERT needs nothing else from the caller.
     *
     * The chunk arithmetic lives on {@see LedgerChainRepair::batches()} rather than here, because
     * the retention sweep rewrites the same rows into the same table and had a different answer
     * for them. A budget that only one of two rewriters reads is not a budget.
     *
     * The rows are NON-EMPTY by contract rather than by a check here. Both callers return early on
     * an empty read of their own ledger long before they get this far, so an emptiness guard in
     * this method is a branch no run can enter — and one that quietly makes `$rows[0]` below look
     * like it needs proving. Stating the precondition in the type is what actually proves it.
     *
     * @param  non-empty-list<array<string, mixed>>  $rows
     */
    private function insertRows(string $table, array $rows): void
    {
        foreach ($this->repair->batches($rows) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /** The notice ledger, which carries no chain — so the rewrite is the erasure and nothing more. */
    private function eraseNotices(string $type, string $id, CarbonImmutable $erasedAt): int
    {
        $rows = LockedRows::settled(static fn (): array => array_values(DB::table('legal_notices')
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all()));

        if ($rows === []) {
            return 0;
        }

        $rewritten = array_map(function (stdClass $row) use ($erasedAt): array {
            $attributes = $this->repair->toRow($row);

            foreach (self::NOTICE_PERSONAL_COLUMNS as $column) {
                $attributes[$column] = null;
            }

            $attributes['subject_erased_at'] = $erasedAt;

            return $attributes;
        }, $rows);

        DB::table('legal_notices')->whereIn('id', array_column($rewritten, 'id'))->delete();

        $this->insertRows('legal_notices', $rewritten);

        return count($rewritten);
    }
}
