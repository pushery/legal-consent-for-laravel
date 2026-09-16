<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Support\DefaultConsentManager;
use RuntimeException;

/**
 * `acknowledge()` was called for a document that is not a flagged informational page.
 *
 * Two different mistakes land here, and the message tells them apart because the fix differs:
 *
 *  - a CONSENT-BEARING document. It has its own acceptance sentence and its own guard, so it goes
 *    through `accept()`. Routing it here would record the caller's sentence over the one the package
 *    rendered, which is the drift {@see DefaultConsentManager::acceptanceFingerprint()}
 *    exists to prevent.
 *  - an informational page the operator has NOT flagged. This is the default and stays refused: a
 *    page that binds nobody does not collect ledger rows because a caller asked nicely. The operator
 *    declares the ones their registration form actually shows, per document, and nothing else can.
 */
final class NotAcknowledgeableException extends RuntimeException
{
    public static function isConsentBearing(string $key, DocumentType $type): self
    {
        return new self(
            "'{$key}' is a {$type->value} document, so it is accepted rather than acknowledged: "
            .'call accept(), which guards the acceptance sentence this package rendered.'
        );
    }

    public static function notFlagged(string $key): self
    {
        return new self(
            "'{$key}' is an informational page and is not flagged for acknowledgment, so nothing "
            ."records it. Set 'acknowledge_at_registration' => true on that document in "
            .'legal-consent.documents if your registration form really shows it.'
        );
    }
}
