<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Enums\BlockingReason;

/**
 * The locales blocking a release, collected under the reason they share.
 *
 * ## Why it groups at all, and the answer is HEIGHT rather than width
 *
 * A consumer reported the admin grid's release column as sitting outside the frame on the right, and
 * proposed moving it to a full row under the document. Measured in a browser at 1728 px with seven
 * locales all blocked: the cell was 486 px wide, nothing in it overflowed, and the table's
 * `scrollWidth` equalled its width — there was no frame to sit outside of.
 *
 * What there WAS, was 210 px of height per blocked row, because the refusal listed one full sentence
 * per language. Six documents then make a grid showing two rows per screen, and the proposed fix
 * would have made that worse. Grouped, the same row is 69-92 px.
 *
 * ## Why here and not in the component
 *
 * It is a pure function of the blocking map, and BOTH shipped manager stubs render it. Held in the
 * grid's row data instead, it would have become a seventh key that six hand-built test fixtures
 * state and a seventh forgets; held in each view, it would be two copies of one rule, which is how
 * the two stubs have drifted before.
 *
 * ## Keyed by the LABEL, and the locales keep their order
 *
 * The key is the translated sentence rather than the enum, because the view renders it and a view
 * that translated a key would be the second place deciding what a reason is called. Insertion order
 * is kept on purpose: the locales arrive in the application's configured order, and an operator
 * reading "not reviewed: de, en" is reading their own list rather than an alphabetised one.
 */
final readonly class BlockingReasonGroups
{
    /**
     * @param  array<string, BlockingReason>  $blocking
     * @return array<string, list<string>>
     */
    public static function of(array $blocking): array
    {
        $groups = [];

        foreach ($blocking as $locale => $reason) {
            $groups[(string) __($reason->label())][] = $locale;
        }

        return $groups;
    }
}
