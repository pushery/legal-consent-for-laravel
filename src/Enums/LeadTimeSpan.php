<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

/**
 * Which span a refused lead time was measured over.
 *
 * The publisher measures two different things under one rule, and they end on two different dates.
 * For a deemed-consent change the statutory period is the objection window — the subject must have
 * the full period to actually object (§ 308 Nr. 5 lit. a BGB; § 675g Abs. 1 for a payment
 * contract) — so the span ends at the objection deadline. For every other change that owes notice
 * it ends at enforcement.
 *
 * The refusal names the span it measured, because one word for both names the wrong date: with
 * announcement 2026-08-01, objection deadline 2026-09-01 and enforcement 2026-11-01, a
 * deemed-consent change is short at the objection deadline, not at enforcement.
 *
 * The cost of the wrong word is not cosmetic. Somebody following the advice would move the
 * enforcement date, which was never too early, and the objection deadline, the one that was,
 * would stay where it was. The publish would be refused again, with the same number.
 */
enum LeadTimeSpan: string
{
    /** Announcement to the objection deadline: the window a deemed-consent change must leave open. */
    case ObjectionDeadline = 'objection_deadline';

    /** Announcement to enforcement: the notice period a scheduled change owes. */
    case Enforcement = 'enforcement';

    /**
     * The translation key of the sentence that words this span for a person.
     *
     * Two keys rather than one with a swapped placeholder: the sentences differ by more than a
     * date, because the date means a different thing. A reader told "between the announcement and
     * the deadline for objections" knows which end to move; the same reader told "and enforcement"
     * moves the wrong one, which is exactly what happened.
     */
    public function label(): string
    {
        return match ($this) {
            self::ObjectionDeadline => 'legal-consent::ui.lead_time_too_short_objection',
            self::Enforcement => 'legal-consent::ui.lead_time_too_short',
        };
    }

    /**
     * The placeholder this span's end date fills in its own sentence.
     *
     * `enforce` is unchanged for the enforcement span, deliberately: that sentence was always
     * right for the branch that reaches it, and a host carrying its own translation of it keeps
     * working. Only the case that was wrong gets a new name.
     */
    public function endPlaceholder(): string
    {
        return match ($this) {
            self::ObjectionDeadline => 'deadline',
            self::Enforcement => 'enforce',
        };
    }
}
