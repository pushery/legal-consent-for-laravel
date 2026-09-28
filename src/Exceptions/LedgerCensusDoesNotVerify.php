<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * The count kept beside a ledger boundary does not verify, and a deletion would have to lower it.
 *
 * Lowering the count means signing it again with the key, which would make whatever count it now
 * holds read as the package's own. So nothing is deleted: the count was altered, or this
 * environment holds a different `tamper_evidence_key`, and either is for a person to look at.
 */
final class LedgerCensusDoesNotVerify extends RuntimeException
{
    public static function below(string $marker, int $boundaryId): self
    {
        return new self(
            "The count of the consent records at or below the ledger boundary {$marker} (#{$boundaryId}) does not verify, so none of them was deleted: it was altered, or this environment holds a different tamper_evidence_key. Run `php artisan legal-consent:verify-ledger` to see what changed."
        );
    }
}
