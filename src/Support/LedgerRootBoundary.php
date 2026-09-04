<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * Where key-bound chain roots begin: every `legal_consents.id` above this was written after the
 * package could prove who opened a chain, so a missing proof up there is a forgery rather than
 * history.
 *
 * A class rather than a `(object)` cast, and the reason is not tidiness. The cast produces
 * `stdClass`, whose properties no analyzer can check — the first version of this read
 * `$boundary->id` and `$boundary->proof` off one, and static analysis could say nothing about
 * either the names or the types. On a value that decides whether a tamper report fires, "the
 * analyzer cannot see this" is the wrong trade for four saved lines.
 */
final readonly class LedgerRootBoundary
{
    public function __construct(
        public int $id,
        public string $proof,
    ) {}
}
