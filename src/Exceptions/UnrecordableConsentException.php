<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * A registration required a mandatory document, the subject ticked it, and no published version
 * exists in ANY locale of the resolution chain — so there is nothing to freeze.
 *
 * This is deliberately loud. Skipping silently is how a ticked checkbox produced an empty ledger:
 * the application believes it holds a consent it cannot prove (Art. 7(1) — the controller must be
 * able to demonstrate consent). Failing the registration is the lesser harm, and it is actionable:
 * publish the document, or stop declaring the key mandatory in `legal-consent.documents`.
 *
 * Distinct from {@see LegalDocumentNotFound}, which means a content SOURCE could not render a text.
 * That one is caught by the drift checker on purpose; this must not be.
 */
final class UnrecordableConsentException extends RuntimeException
{
    /**
     * @param  list<string>  $chain  the locales that were tried, in order
     */
    public static function for(string $documentKey, array $chain): self
    {
        return new self(sprintf(
            "Registration required consent to '%s', but no active version exists in any of [%s]. "
            .'Publish the document (legal-consent:publish) or remove it from the mandatory registry — '
            .'recording nothing would leave a consent the app cannot prove.',
            $documentKey,
            implode(', ', $chain),
        ));
    }
}
