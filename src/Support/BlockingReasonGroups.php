<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Enums\BlockingReason;

/**
 * The locales blocking a release, collected under the reason they share.
 *
 * ## Why it groups at all, and the answer is height rather than width
 *
 * The release column fits its cell: at 1728 px with seven locales all blocked, the cell is 486 px
 * wide, nothing in it overflows, and the table's `scrollWidth` equals its width. A full row under
 * the document would not help.
 *
 * Its height is what grows. Listed one full sentence per language, a blocked row is 210 px tall, so
 * six documents make a grid that shows two rows per screen. Grouped by reason, the same row is
 * 69-92 px.
 *
 * ## Why here and not in the component
 *
 * It is a pure function of the blocking map, and both shipped manager stubs render it. Held in the
 * grid's row data instead, it would be one more key every row has to carry; held in each view, it
 * would be two copies of one rule, and two copies drift.
 *
 * ## Keyed by the LABEL, and the locales keep their order
 *
 * The key is the translated sentence rather than the enum, because the view renders it and a view
 * that translated a key would be the second place deciding what a reason is called. Insertion order
 * is kept on purpose: the locales arrive in the application's configured order, and an operator
 * reading "not reviewed: de, en" is reading their own list rather than an alphabetized one.
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
