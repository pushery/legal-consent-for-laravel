<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * Thrown when a consent is written for a subject that is not a party to the document.
 *
 * A registry entry that names `concluded_by` is concluded by an instance of that model, a workspace
 * or a team, and by nobody else. A ledger row saying that a person accepted it asserts an acceptance
 * by somebody who is not a party. The ledger is append-only, so such a row could not be corrected:
 * it would simply stand, and every later reader would take it as proof.
 *
 * The sibling of {@see NotConsentBearingException}, which refuses the same kind of row for a page
 * that binds nobody.
 */
final class NotAPartyException extends RuntimeException
{
    public static function for(string $documentKey, string $party): self
    {
        return new self(
            "Document '{$documentKey}' is concluded by {$party}, so it is recorded for an instance of "
            .'that model and for no other subject.'
        );
    }
}
