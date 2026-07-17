<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * No acceptance sentence could be resolved for a document in its own locale.
 *
 * The acceptance wording is the sentence a subject clicks "I accept" on, snapshotted
 * verbatim into every consent row (`ui_wording_snapshot`) as proof of what they agreed
 * to. Resolving it against the wrong locale — or silently substituting a hardcoded
 * fallback in another language — would freeze a sentence the subject never saw into an
 * append-only, unfixable ledger row. When neither the type-specific key nor the
 * `default` key resolves in the document's locale, the pipeline fails loud instead.
 */
final class MissingAcceptanceWording extends RuntimeException
{
    public static function for(string $type, string $locale): self
    {
        return new self(
            "No acceptance wording for '{$type}' in '{$locale}'. Add 'legal-consent::wording.{$type}' or 'legal-consent::wording.default' for that locale — the acceptance sentence is frozen into the consent ledger and must be in the subject's own language."
        );
    }
}
