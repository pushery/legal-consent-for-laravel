<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

/**
 * Whether a human has signed off on a draft's EXACT current bytes.
 *
 * Deliberately separate from staleness: "a human approved this text" and "this text still matches
 * the source it was translated from" are different questions, and conflating them is how a review
 * gate silently stops meaning anything. Only an explicit human act sets Reviewed; every write to a
 * draft's body resets it to Draft.
 */
enum ReviewState: string
{
    /** Being worked on, or changed since the last sign-off. Not publishable. */
    case Draft = 'draft';

    /** A human confirmed these exact bytes. The only state the publish gate accepts. */
    case Reviewed = 'reviewed';

    /**
     * The translation key for this state, for a screen a person reads.
     *
     * ⚠️ THE STORED VALUE IS NOT A WORD, and the two only look alike in English. `draft` and
     * `reviewed` are storage tokens; rendering them raw put untranslated English on a compliance
     * screen in every other locale, which is how this was found — by a consumer running a German
     * admin surface.
     *
     * Written case by case rather than derived from the value. A derivation would silently hand a
     * third state its own token as a label; `match` without a default throws instead, which is the
     * behavior worth having on a surface where a wrong word is a compliance statement.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'legal-consent::ui.review_state_draft',
            self::Reviewed => 'legal-consent::ui.review_state_reviewed',
        };
    }
}
