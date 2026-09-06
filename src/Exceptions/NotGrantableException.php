<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Pushery\LegalConsent\Enums\DocumentType;
use RuntimeException;

/**
 * Thrown when the settings screen is asked to GRANT a document that is not a voluntary consent.
 *
 * Granting is the one direction on that screen which WRITES an assertion that the subject agreed,
 * and the surface it belongs on follows from the legal class, not from convenience:
 *
 *  - a CONTRACT and an ACKNOWLEDGMENT are mandatory. They are accepted where they are presented
 *    in full — a registration form, the re-consent gate, a first-use interstitial — because the
 *    acceptance has to be informed (Art. 7(1)), and a toggle beside a title is not a presentation
 *    of a contract. Accepting one from a settings list would freeze a version the subject was
 *    shown a heading of.
 *  - an INFORMATIONAL page binds nobody, so there is nothing to grant.
 *
 * That leaves the voluntary consent, which is exactly the one the settings screen is for: it may
 * be given and taken back freely, and neither direction may ever block anything (Art. 7(4)).
 *
 * The ledger is append-only, so a row claiming an acceptance that was never informed cannot be
 * corrected later; it simply stands, and every later reader takes it at face value.
 */
final class NotGrantableException extends RuntimeException
{
    public static function for(string $documentKey, DocumentType $type): self
    {
        $reason = $type->isConsentBearing()
            ? 'a mandatory document is accepted where its full text is presented, not from a settings list (Art. 7(1))'
            : 'an informational page binds nobody, so there is nothing to grant';

        return new self(
            "Document '{$documentKey}' ({$type->value}) cannot be granted here — {$reason}."
        );
    }
}
