<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * A content source could not resolve a document for a (type, locale).
 */
final class LegalDocumentNotFound extends RuntimeException
{
    /**
     * The reference the ACTIVE-VERSION lookup identifies itself by.
     *
     * Named here rather than spelled out twice, because two places have to agree on it and only one
     * of them would notice if they drifted: the manager passes it when its own lookup comes up
     * empty, and the HTTP layer reads it to decide whether this exception is a client error at all.
     */
    public const string PUBLISHED_LOOKUP = 'legal_documents';

    private ?string $reference = null;

    public static function forSource(string $type, string $locale, ?string $reference = null): self
    {
        $where = $reference === null ? '' : " at [{$reference}]";

        $exception = new self("No legal document found for type '{$type}' and locale '{$locale}'{$where}.");
        $exception->reference = $reference;

        return $exception;
    }

    /**
     * Whether the ACTIVE-VERSION lookup is what failed — as opposed to a content source, a draft
     * store or a Markdown file.
     *
     * Only the first one is a client error, and the difference is not academic. This same exception
     * type is raised while RENDERING a document, which a consuming application's listener on
     * `ConsentRecorded` can trigger long AFTER the ledger row is committed. Answering that with
     * "unknown_document" would tell a client that a published key does not exist and invite a retry
     * that appends a SECOND row to an append-only ledger — while the listener's real bug is
     * swallowed and surfaces nowhere.
     */
    public function isMissingPublishedVersion(): bool
    {
        return $this->reference === self::PUBLISHED_LOOKUP;
    }
}
