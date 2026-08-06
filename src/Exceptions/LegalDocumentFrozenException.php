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

    /**
     * A published change description is frozen for the same reason the document is: it is the
     * record of what the subject was TOLD about the change, and a notice that has gone out cannot
     * be given a different account of itself afterwards.
     */
    public static function forChangeSet(string $key, string $locale, string $version): self
    {
        return new self(
            "the change description for '{$key}' {$version} ({$locale}) is published and frozen — it "
            .'records what subjects were told. Describe a correction in the next version instead.'
        );
    }

    public static function forChangeItem(int $changeSetId, int $position): self
    {
        return new self(
            "change item #{$position} of published change set {$changeSetId} is frozen — it records "
            .'what subjects were told. Describe a correction in the next version instead.'
        );
    }
}
