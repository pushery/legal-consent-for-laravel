<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Decides whether silence binds a single subject when a deemed-consent objection window closes.
 *
 * Extracted from the sweep because this is the one judgment in the package that CREATES consent
 * out of inaction (§ 308 Nr. 5 BGB) — it deserves to be readable and testable on its own rather
 * than buried in a streaming loop where its branches are only reachable under real concurrency.
 */
final class DeemedAcceptanceDecision
{
    /**
     * @param  LegalConsent|null  $latest  the subject's most recent action for this document+locale,
     *                                     read live (NOT from the sweep's ledger snapshot)
     */
    public function shouldDeem(?LegalConsent $latest, LegalDocument $version, bool $noticeProved): bool
    {
        // § 308 Nr. 5 lit. b BGB makes the special warning — that not objecting counts as agreement
        // — a VALIDITY CONDITION of the fiction, not courtesy copy. Without it silence does not
        // bind, so a DeemedAccepted row written here would be a consent record the package can
        // refute from its own proof table. `$noticeProved` is that table's answer: a legal_notices
        // row for this (subject, version) whose mandatory content actually rendered.
        //
        // This parameter is REQUIRED rather than defaulted to true on purpose. A default would let
        // any future call site create consent out of silence by simply not passing it, which is the
        // one mistake this check exists to prevent.
        if (! $noticeProved) {
            return false;
        }

        // No recorded action at all: the affected-subject set already established that they hold an
        // older major, so silence binds.
        if (! $latest instanceof LegalConsent) {
            return true;
        }

        // They answered the notice in time — silence never bound them.
        if ($latest->action === ConsentAction::Objected || $latest->action === ConsentAction::Terminated) {
            return false;
        }
        // They already hold this version — typically an EXPRESS acceptance that landed after the
        // sweep took its ledger snapshot. The snapshot deliberately freezes the paged set, so this
        // live check is the only thing that can see it; without it a real, actively-given consent
        // would be recorded a second time as "bound by silence".
        if (! $latest->action->isAccepting()) {
            return true;
        }

        // Compare the VERSION, not the major. A deemed-consent change is lawful only for a minor,
        // peripheral change (BGH XI ZR 26/20), and the publisher enforces that by refusing the mode
        // on a major bump of a contract — so under a major comparison the subject's major always
        // equalled the version's and silence bound nobody, which is the same assumption that made
        // the notice sweep select nobody. Only the ACTIVE version is ever swept and the publisher
        // refuses a downgrade, so a subject cannot hold anything newer: "not this version" is
        // exactly "older than this version" here.
        return $latest->document_version !== $version->version;
    }
}
