<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * The HTML parser stopped before the end of a legal text, so the sanitized output is a FRAGMENT.
 *
 * This is refused rather than rendered, and the reason is the hash. `content_hash` is taken over
 * the sanitizer's output, and the resulting row is append-only proof of the text somebody agreed
 * to. Truncate silently and the ledger makes a confident claim about a document the operator never
 * published — the one question this package exists to answer, answered wrongly, with no signal.
 *
 * The only cause seen so far is libxml's hard nesting limit of 256 elements. Measured: 260 nested
 * `<div>`s produce exactly one error, and it is FATAL, while unclosed tags, stray closing tags,
 * unquoted attributes, entity smuggling, CDATA, processing instructions and NUL bytes produce
 * either nothing or a recoverable error and parse in full. That is why the refusal keys on the
 * error LEVEL and not on a message: recoverable noise is what an HTML parser is for.
 */
final class LegalDocumentUnparsable extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self(
            'The legal text could not be parsed in full and would have been silently truncated, '
            ."so it was refused instead: {$reason}"
        );
    }
}
