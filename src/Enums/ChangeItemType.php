<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

/**
 * What one entry in a change description DOES to the subject's position.
 *
 * The six are not a taxonomy of edits — they are the distinctions that decide what else the notice
 * owes. A clarification and a restriction can be the same number of words and are not the same
 * legal event: only one of them can make a change disadvantageous, and a disadvantageous change is
 * what triggers the objection and free-termination lines (§ 308 Nr. 5, § 675g Abs. 2, § 327r).
 *
 * `Extended` is the case the naive list misses. Something that was optional becoming mandatory
 * removes a choice while adding nothing — the sub-processors that "will become required" in the
 * Productboard notice. Filed as an addition it reads as a bonus; it is a narrowing.
 */
enum ChangeItemType: string
{
    /** Something new: a clause, a recipient, a purpose. */
    case Added = 'added';

    /** Something gone. Usually favorable, occasionally not — a removed service is a loss. */
    case Removed = 'removed';

    /** Something with different content, neither plainly wider nor narrower. */
    case Modified = 'modified';

    /** The same rule, said more clearly. No change in substance — the only purely neutral entry. */
    case Clarified = 'clarified';

    /** Broader reach: an optional processor becoming mandatory, a purpose covering more data. */
    case Extended = 'extended';

    /** Narrower position: a right limited, a liability excluded, an entitlement reduced. */
    case Restricted = 'restricted';

    /**
     * ⚠️ THE `true` IN THE TWO `in_array()` CALLS BELOW IS UNOBSERVABLE, AND THAT IS NOT A REASON
     * TO DROP IT. Enum cases are singletons, so a loose comparison between two of them already
     * decides on identity -- measured: dropping the strict flag from either call leaves the whole
     * tree green. It earns its place the moment either side stops being pure enum cases, which is
     * a one-line edit away, and it costs nothing until then.
     */
    /**
     * Does this entry, on its own, make the change disadvantageous to the subject?
     *
     * Deliberately conservative. `Removed` counts even though a removal is often a gift, because
     * the harm of over-informing is one extra sentence and the harm of under-informing is a notice
     * missing a mandatory line. `Modified` does NOT count — a change that is neither wider nor
     * narrower cannot be classified from its type alone, and treating every edit as adverse would
     * make the signal mean nothing.
     */
    public function isAdverse(): bool
    {
        return in_array($this, [self::Removed, self::Extended, self::Restricted], true);
    }

    /**
     * Does this entry describe a THIRD PARTY that now handles the subject's data?
     *
     * Only these carry the EDPB Opinion 22/2024 Rz. 22 facets (who, where, contact, purpose), and
     * only for these is an incomplete facet set a defect rather than an empty field.
     */
    public function namesAParty(): bool
    {
        return in_array($this, [self::Added, self::Extended, self::Removed], true);
    }
}
