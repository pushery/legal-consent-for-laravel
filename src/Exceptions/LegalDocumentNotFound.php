<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Pushery\LegalConsent\Enums\BlockingReason;
use RuntimeException;

/**
 * A content source could not resolve a document for a (type, locale).
 */
final class LegalDocumentNotFound extends RuntimeException
{
    /**
     * The reference the active-version lookup identifies itself by.
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
     * The draft store's refusal of one language, with its reason and the command that moves it on.
     *
     * The plain sentence of {@see forSource()} read like a missing text where the text was there and
     * waited for a sign-off, and an operator on a server could not tell which. It stays the opening
     * of this message, so whatever matched it before still does.
     */
    public static function inTheDraftStore(string $type, string $locale, BlockingReason $reason): self
    {
        $next = match ($reason) {
            BlockingReason::NoDraft => "Write it in the editor, or fill the store from the source files with php artisan legal-consent:draft-from-source {$type}.",
            BlockingReason::NotReviewed => "Sign it off in the editor, or with php artisan legal-consent:review {$type} --locales={$locale} --by=<who reviewed it>.",
            BlockingReason::StaleTranslation => "Review it again against the changed source, in the editor or with php artisan legal-consent:review {$type} --locales={$locale} --by=<who reviewed it>.",
            default => '',
        };

        $exception = new self(rtrim("No legal document found for type '{$type}' and locale '{$locale}' at [legal_drafts]: {$reason->value}. {$next}"));
        $exception->reference = 'legal_drafts';

        return $exception;
    }

    /**
     * Whether the active-version lookup is what failed — as opposed to a content source, a draft
     * store or a Markdown file.
     *
     * Only the first one is a client error, and the difference is not academic. This same exception
     * type is raised while rendering a document, which a consuming application's listener on
     * `ConsentRecorded` can trigger long after the ledger row is committed. Answering that with
     * "unknown_document" would tell a client that a published key does not exist and invite a retry
     * that appends a second row to an append-only ledger — while the listener's real bug is
     * swallowed and surfaces nowhere.
     */
    public function isMissingPublishedVersion(): bool
    {
        return $this->reference === self::PUBLISHED_LOOKUP;
    }
}
