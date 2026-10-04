<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant resolver answers with something that is no tenant id.
 *
 * Documents and consents are kept apart by the id the resolver returns. A resolver that returns
 * nothing describes a context without a tenant, such as the console or a guest, and that context
 * shares one bucket. An answer of another kind is a resolver that is set up wrongly. Read as no
 * tenant, it would put every tenant into that shared bucket, where each one sees the documents
 * and consents of the others, so it is refused instead.
 */
final class UnresolvableTenant extends RuntimeException
{
    public static function from(mixed $answer, string $resolver): self
    {
        return new self(sprintf(
            "The tenant resolver set through TenantContext::%s() returned %s, which is no tenant id. Return the tenant's key as an int or a string (for a model, its getKey()), or null where there is no tenant. Documents and consents are kept apart by that id, and an answer read as no tenant would put every tenant into one shared bucket.",
            $resolver,
            get_debug_type($answer),
        ));
    }
}
