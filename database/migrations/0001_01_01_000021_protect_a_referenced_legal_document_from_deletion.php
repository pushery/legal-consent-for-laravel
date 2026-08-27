<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Pushery\LegalConsent\Support\ProofColumnGuard;

/**
 * Arm the BEFORE DELETE guard on `legal_documents`: a version a consent points at can no longer be
 * deleted, on any of the three engines.
 *
 * 000011 froze the columns of a published row and stopped there, and the gap that left is the one
 * the whole ledger rests on. The full text a subject accepted exists exactly ONCE, in
 * `legal_documents.content`. A ledger row denormalizes the key, the version, the hash and the one
 * acceptance sentence — never the text. A hash can verify a text somebody puts in front of it; it
 * cannot produce one. So a DELETE of a superseded version left every consent recorded against it
 * holding a fingerprint of a document that no longer exists anywhere, with nothing looking wrong:
 * `verify-ledger` checks the chain rather than the reachability of the text, `verify-documents`
 * only reads rows that are still there, and `history()` keeps returning the hash it always did.
 *
 * The asymmetry that made this visible is inside this package. `legal_change_sets` and
 * `legal_change_items` — descriptions OF a change — have carried a BEFORE DELETE guard since they
 * were created, and `legal_notices` keeps its own copy of the delivered text in `notice_body`. The
 * package proved what it TOLD a subject better than what a subject AGREED to.
 *
 * The guard is conditional, and the condition is the part that keeps it honest: a version nobody
 * ever consented to stays deletable. Retirement of a version that does bind somebody runs through
 * `is_active = false`, which is what that column exists for. A guard that refused every delete
 * would be routed around rather than kept.
 *
 * The trigger SQL lives in {@see ProofColumnGuard} with the UPDATE guard, and for the same reason:
 * SQLite rebuilds a table to alter a column and a rebuild DROPS TRIGGERS, so any later migration
 * touching this table has to be able to put both back. `install()` is idempotent — it drops first —
 * so re-running it here is how an installation that already ran 000011 receives the new arm.
 *
 * NOTE for anyone reading a failing DELETE as a defect: it is the guard, and the message says
 * which column to write instead. Deleting the ledger rows first is not the way around it either —
 * `legal_consents` is append-only on every engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProofColumnGuard::install();
    }

    /**
     * Down leaves the guard armed, and that is the considered answer rather than a missing one.
     *
     * The guard is one object with one entry point: `install()` puts both arms on, `uninstall()`
     * takes both off. Subtracting only the DELETE arm would mean a second, older copy of the
     * trigger SQL living in this migration — the exact duplication that keeping it in `src/` was
     * meant to prevent, and the copy would then be the one that rots. Taking BOTH arms off would
     * be worse: rolling back one migration would leave `legal_documents` un-frozen while 000011 is
     * still recorded as applied, which is the failure with no symptom.
     *
     * So the round trip is a no-op by design, and 000011's own `down()` — `uninstall()` — remains
     * the way the guard comes off. Re-running `install()` here keeps that explicit and idempotent
     * instead of leaving an empty body that reads like an oversight.
     */
    public function down(): void
    {
        ProofColumnGuard::install();
    }
};
