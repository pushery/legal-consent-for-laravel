<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * A source declared a version string that is not a bare SemVer core (`MAJOR.MINOR.PATCH`).
 *
 * The version is parsed with a plain integer cast, so a `v` prefix or any non-numeric
 * leading segment silently becomes major 0 — and because the re-consent gate compares
 * major versions, every subsequent comparison is `0 < 0` and the gate never fires while
 * the row still records `requires_reconsent = true`. A material change would then take
 * effect with no notice and no re-consent, looking compliant in an audit. The pipeline
 * refuses such a version rather than normalizing it: a version is legally load-bearing,
 * so a malformed one is an error to surface, not a value to guess at.
 */
final class InvalidDocumentVersion extends RuntimeException
{
    public static function for(string $type, string $locale, string $version): self
    {
        return new self(
            "Legal document '{$type}' ({$locale}) declares version '{$version}', which is not a MAJOR.MINOR.PATCH SemVer core (e.g. 1.0.0). Versions drive the re-consent gate, so a malformed one is refused — drop any 'v' prefix or pre-release/build suffix."
        );
    }
}
