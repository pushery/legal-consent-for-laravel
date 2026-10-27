<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Models\LegalChangeSet;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Whether a change owes the subject a free right to terminate.
 *
 * Owed where the version offers one, or where an entry of its change description takes something
 * away: § 675g Abs. 2 Satz 3 BGB ties the termination notice to the change being disadvantageous,
 * not to a checkbox. The notices decide here whether they print the termination line, and the
 * re-consent form decides here whether it offers the termination, so a page never offers a way out
 * the notice did not name, and never leaves out one it did.
 */
final class FreeTermination
{
    public static function owed(LegalDocument $document, ?LegalChangeSet $changeSet): bool
    {
        return $document->offers_termination || self::adverse($changeSet);
    }

    /**
     * Does any entry make the change disadvantageous? A notice that takes something away owes the
     * exit route unconditionally, not only where the operator remembered to offer one.
     */
    public static function adverse(?LegalChangeSet $changeSet): bool
    {
        if (! $changeSet instanceof LegalChangeSet) {
            return false;
        }

        foreach ($changeSet->items as $item) {
            if ($item->type->isAdverse()) {
                return true;
            }
        }

        return false;
    }
}
