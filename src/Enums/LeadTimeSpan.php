<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

/**
 * Which span a refused lead time was measured over.
 *
 * The publisher measures two different things under one rule, and they end on two different dates.
 * For a deemed-consent change the statutory period is the OBJECTION window — the subject must have
 * the full period to actually object (§ 308 Nr. 5 lit. a BGB; § 675g Abs. 1 for a payment
 * contract) — so the span ends at the objection deadline. For every other change that owes notice
 * it ends at enforcement.
 *
 * THE REFUSAL USED TO CALL BOTH OF THEM "ENFORCEMENT", AND THAT IS WHY THIS EXISTS. The
 * deemed-consent branch handed the objection deadline to a parameter named `enforceAt`, so the
 * sentence an operator read named the wrong date under the wrong word — in the English message and,
 * once the sentence was translated, in all seven languages. A reported example: announcement
 * 2026-08-01, objection deadline 2026-09-01, enforcement 2026-11-01, and the refusal said
 * enforcement was 2026-09-01.
 *
 * The cost of that is not cosmetic. Somebody following the advice moves the ENFORCEMENT date,
 * which was never too early, and the objection deadline — the one that was — stays where it was.
 * The publish is refused again, and the number they were given has not moved either.
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
