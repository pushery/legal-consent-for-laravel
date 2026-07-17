<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * Thrown when something tries to change a proof column of a published `legal_documents`
 * row. A published version is frozen evidence — the exact sanitized text a subject was
 * shown, and the hash the ledger snapshots (EDPB 05/2020 Rz. 108). Correcting a legal text
 * is a NEW published version, never an in-place edit. Only the operational columns
 * `is_active`, `updated_at`, `notified_at` and `objection_closed_at` may change after
 * publish; everything else is frozen.
 */
final class LegalDocumentFrozenException extends RuntimeException
{
    /**
     * @param  list<string>  $columns  the frozen columns a caller tried to change
     */
    public static function for(array $columns): self
    {
        sort($columns);

        return new self(
            'legal_documents rows are frozen proof — cannot change '.implode(', ', $columns)
            .' on a published version. Publish a new version instead (EDPB 05/2020 Rz. 108).'
        );
    }
}
