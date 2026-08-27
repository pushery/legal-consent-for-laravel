<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * A published version was deleted while consent rows still prove themselves against it.
 *
 * The full text a subject was shown exists exactly once, in `legal_documents.content`. The ledger
 * row beside it carries the version, the content hash and the acceptance sentence — a fingerprint,
 * which can VERIFY a text somebody produces but cannot produce one. Delete the version and every
 * acceptance of it keeps looking intact while the answer to "what exactly did this person agree
 * to?" (Art. 7(1)) no longer exists anywhere.
 *
 * Retirement is `is_active = false`, which is what that column is for: the version stops being
 * enforced, the text stays on file, and a subject can still withdraw against the version they
 * accepted. A version nobody ever accepted deletes normally.
 *
 * The sibling of {@see LegalDocumentFrozenException}, which refuses the UPDATE side.
 */
final class LegalDocumentInEvidenceException extends RuntimeException
{
    public static function for(string $documentKey, string $version, string $locale, int $consents): self
    {
        return new self(sprintf(
            "Cannot delete version %s of '%s' (%s): %d consent record(s) prove themselves against its text. "
            .'The ledger stores a hash of that text, not the text — deleting it destroys the Art. 7(1) evidence '
            .'for every acceptance already recorded. Retire the version with is_active = false instead; it stops '
            .'being enforced and stays provable.',
            $version,
            $documentKey,
            $locale,
            $consents,
        ));
    }
}
