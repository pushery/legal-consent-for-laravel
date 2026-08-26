<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
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
 * ⚠️ IDS ARE PRESERVED, AND THAT IS LOAD-BEARING RATHER THAN TIDY. The verifier flags any row with
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
     * ⚠️ `notice_body` STAYS, and it is the one that looks like it should go. It holds the exact
     * message served, which reads like per-person data — it is not: the dispatch renders the proof
     * ONCE PER VERSION and hands the same text to everyone who received it (`renderProof()` takes
     * a version and no subject). Clearing it would destroy the durable-medium proof of what was
     * communicated — the thing that makes a deemed acceptance binding at all (§ 308 Nr. 5 lit. b) —
     * and remove nothing about the person. If a consumer ever renders that body per subject, this
     * list is what has to change with it.
     */
    private const array NOTICE_PERSONAL_COLUMNS = ['subject_type', 'subject_id'];

    public function __construct(private LedgerChainRepair $repair = new LedgerChainRepair) {}

    public function forget(Model $subject): SubjectErasure
    {
        $type = $subject->getMorphClass();
        $id = $subject->getKey();

        if (! is_int($id) && ! is_string($id)) {
            return new SubjectErasure;
        }

        return DB::transaction(function () use ($type, $id): SubjectErasure {
            $erasedAt = CarbonImmutable::now();

            [$consents, $rechained] = $this->eraseConsents($type, $id, $erasedAt);

            return new SubjectErasure(
                consents: $consents,
                notices: $this->eraseNotices($type, $id, $erasedAt),
                rechained: $rechained,
            );
        });
    }

    /**
     * The consent ledger, which is the one that carries a chain.
     *
     * @return array{0: int, 1: int} rows erased, rows re-linked
     */
    private function eraseConsents(string $type, int|string $id, CarbonImmutable $erasedAt): array
    {
        // Every row this subject's chain touches, not only the rows still naming them. A previous
        // erasure leaves rows with a null subject and the same token, and their hashes are inputs
        // to the links of everything after them — walking only the still-named rows would rebuild
        // half a chain onto the other half's stale values.
        $tokens = DB::table('legal_consents')
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->whereNotNull('subject_token')
            ->distinct()
            ->pluck('subject_token')
            ->all();

        $rows = DB::table('legal_consents')
            ->where(function (Builder $query) use ($type, $id, $tokens): void {
                $query->where(fn (Builder $named): Builder => $named->where('subject_type', $type)->where('subject_id', $id));

                if ($tokens !== []) {
                    $query->orWhereIn('subject_token', $tokens);
                }
            })
            ->orderBy('id')
            ->get()
            ->all();

        if ($rows === []) {
            return [0, 0];
        }

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
        $rechained = $this->repair->chainedCount($rewritten);
        $rewritten = $this->repair->relink($rewritten);

        // Delete before insert, in one transaction, so the unique chain-link index from 000012 is
        // never asked to hold two rows with the same (token, prev_record_hash) at once.
        DB::table('legal_consents')->whereIn('id', array_column($rewritten, 'id'))->delete();

        foreach ($rewritten as $attributes) {
            DB::table('legal_consents')->insert($attributes);
        }

        return [$erased, $rechained];
    }

    /** The notice ledger, which carries no chain — so the rewrite is the erasure and nothing more. */
    private function eraseNotices(string $type, int|string $id, CarbonImmutable $erasedAt): int
    {
        $rows = DB::table('legal_notices')
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->orderBy('id')
            ->get()
            ->all();

        if ($rows === []) {
            return 0;
        }

        $rewritten = array_values(array_map(function (stdClass $row) use ($erasedAt): array {
            $attributes = $this->repair->toRow($row);

            foreach (self::NOTICE_PERSONAL_COLUMNS as $column) {
                $attributes[$column] = null;
            }

            $attributes['subject_erased_at'] = $erasedAt;

            return $attributes;
        }, $rows));

        DB::table('legal_notices')->whereIn('id', array_column($rewritten, 'id'))->delete();

        foreach ($rewritten as $attributes) {
            DB::table('legal_notices')->insert($attributes);
        }

        return count($rewritten);
    }
}
