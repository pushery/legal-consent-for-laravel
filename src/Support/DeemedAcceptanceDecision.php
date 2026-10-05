<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Enums\NoticeMode;
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

        // No recorded action at all: nothing answered the notice, and its proof is in place, so
        // silence binds.
        if (! $latest instanceof LegalConsent) {
            return true;
        }

        // They answered the notice in time — silence never bound them.
        if ($latest->action === ConsentAction::Objected || $latest->action === ConsentAction::Terminated) {
            return false;
        }
        // Anything that is neither an answer to the notice nor an acceptance — a withdrawal, a
        // decline, an opt-in request still awaiting confirmation. All of them leave the subject
        // holding nothing, which is exactly who the fiction is for, so silence binds.
        //
        // This has to answer BEFORE the version comparison below, and at the current version the
        // two disagree: `document_version !== $version->version` is false there, so without this
        // return a withdrawal recorded against the current text would read as "already settled".
        // A withdrawal is not an answer to a change that came after it.
        //
        // The paragraph that used to sit here — "they already hold this version, typically an
        // EXPRESS acceptance that landed after the sweep's snapshot" — describes the RETURN AT THE
        // BOTTOM, not this branch. It was one statement too high, which read as if a non-accepting
        // action meant the subject already held the version. It means the opposite.
        if (! $latest->action->isAccepting()) {
            return true;
        }

        // An ACCEPTING action, so the only question left is which version it accepted. Matching
        // this one means an express acceptance landed after the sweep took its ledger snapshot —
        // the snapshot deliberately freezes the paged set, so this live read is the only thing
        // that can see it, and without the comparison a real, actively-given consent would be
        // recorded a second time as "bound by silence".
        //
        // Compare the VERSION, not the major. A deemed-consent change is lawful only for a minor,
        // peripheral change (BGH XI ZR 26/20), and the publisher enforces that by refusing the mode
        // on a major bump of a contract — so under a major comparison the subject's major always
        // equaled the version's and silence bound nobody, which is the same assumption that made
        // the notice sweep select nobody. Only the ACTIVE version is ever swept and the publisher
        // refuses a downgrade, so a subject cannot hold anything newer: "not this version" is
        // exactly "older than this version" here.
        return $latest->document_version !== $version->version;
    }

    /**
     * The subject's most recent action for $version's document and locale that has a say in whether
     * silence binds them to it, read live.
     *
     * An objection has one when it was recorded against $version itself, or when it was a
     * Widerspruch against an earlier deemed change, declared inside that change's objection window:
     * that subject never took the version $version builds on, and silence must not bind them to
     * what they rejected. An objection recorded while no change was open to it answered nothing
     * (§ 308 Nr. 5 BGB: the period to object begins with the notice). It is passed over, and the
     * action before it decides, so a subject who ended the contract before objecting stays ended.
     */
    public function latestThatCounts(Model $subject, LegalDocument $version): ?LegalConsent
    {
        $subjectId = SubjectKey::for($subject);

        // A subject without a key names nobody, and `subject_id = null` would read rows of its type
        // written without a key as this one's.
        if ($subjectId === null) {
            return null;
        }

        $rows = LegalConsent::model()::query()
            ->where('subject_type', (string) $subject->getMorphClass())
            ->where('subject_id', $subjectId)
            ->where('document_key', $version->key)
            ->where('locale', $version->locale)
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->cursor();

        foreach ($rows as $row) {
            if ($row->action !== ConsentAction::Objected || $this->answersAChange($row, $version)) {
                return $row;
            }
        }

        return null;
    }

    private function answersAChange(LegalConsent $objection, LegalDocument $version): bool
    {
        // An objection whose version cannot be read is kept: passing over one that did answer a
        // change would bind its subject by silence.
        if ($objection->document_id === null || $objection->document_id === $version->id) {
            return true;
        }

        $objected = LegalDocument::model()::query()->withoutGlobalScopes()->find($objection->document_id);

        if (! $objected instanceof LegalDocument) {
            return true;
        }

        return $objected->notice_mode === NoticeMode::DeemedConsent
            && $objected->announce_from !== null
            && $objected->objection_deadline !== null
            && $objection->accepted_at->betweenIncluded($objected->announce_from, $objected->objection_deadline);
    }
}
