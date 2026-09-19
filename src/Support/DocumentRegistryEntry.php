<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * One document's entry in the configured registry, read once and narrowed properly.
 *
 * EXTRACTED RATHER THAN COPIED. {@see RegistrationAcknowledgment} carried this reader privately,
 * and {@see RegistrationField} needs the same one. A second copy of a config reader is the drift
 * this package argues against everywhere else — the two would agree today and answer differently
 * the first time either learns something about a malformed registry.
 */
final readonly class DocumentRegistryEntry
{
    /**
     * REBUILT RATHER THAN RETURNED, for the reason {@see LedgerChainRepair::toRow()} gives about a
     * database row: `config()` hands back `mixed`, `is_array()` narrows it only to
     * `array<mixed, mixed>`, and a registry entry never has integer keys — but nothing in the type
     * system says so. An inline `@var` would ASSERT that; the loop ESTABLISHES it, and costs one
     * pass over a handful of options.
     *
     * @return array<string, mixed>|null
     */
    public static function for(string $key): ?array
    {
        $documents = config('legal-consent.documents', []);

        if (! is_array($documents) || ! isset($documents[$key]) || ! is_array($documents[$key])) {
            return null;
        }

        $entry = [];

        foreach ($documents[$key] as $option => $value) {
            $entry[(string) $option] = $value;
        }

        return $entry;
    }

    /**
     * One option off that entry, or null when the key names no document or carries no such option.
     */
    public static function option(string $key, string $option): mixed
    {
        return self::for($key)[$option] ?? null;
    }
}
