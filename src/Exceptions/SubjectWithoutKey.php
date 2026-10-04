<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * Thrown when a consent is recorded for a subject that has no key the ledger can store: a model
 * that is not saved yet, or one whose key is neither an integer nor a string.
 *
 * Such a row would carry no `subject_id`. Nothing that reads a subject's consents finds it, and it
 * cannot be told apart from the row of a subject who was erased, so the retention sweep removes it
 * as an orphan. Erasing a subject without a key is refused for the same reason.
 */
final class SubjectWithoutKey extends RuntimeException
{
    public static function for(string $subjectType): self
    {
        return new self(
            "Subject '{$subjectType}' has no key the consent ledger can store: a model that is not saved yet, or a key that is neither an integer nor a string. Save the model before recording its consent. A row without a key names nobody, and the retention sweep would remove it like the row of an erased subject."
        );
    }
}
