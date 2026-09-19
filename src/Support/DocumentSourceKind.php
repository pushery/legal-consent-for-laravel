<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use InvalidArgumentException;
use Pushery\LegalConsent\Content\Drivers\DraftDocumentSource;
use Pushery\LegalConsent\Content\LegalDocumentSource;

/**
 * Does this document take its text from somewhere OTHER than the draft store?
 *
 * ONE question, asked by two readers who used to answer it differently — and only one of them
 * knew it was a question at all. {@see LegalDocumentReleaser} refuses such a document outright;
 * the readiness the admin overview builds from {@see LegalDraftSet::blockingLocales()} asked
 * about drafts, review and staleness and never about the source. So the overview armed a button
 * over a release the releaser then refused, which is a control that answers with a refusal.
 *
 * ## Why it is three states collapsed into a predicate, and which two
 *
 * The question is "is the source something OTHER than the draft store", never "is there one". A
 * document with no resolvable source is a misconfiguration that already has owners — the draft
 * pre-flight reports it as a missing draft, the publisher as a missing source — and turning that
 * unknown into this refusal would rename an error a caller is already catching. Measured once
 * before: a suite configuring no registry at all had a release start answering
 * `InvalidArgumentException` where it used to answer `LegalReleaseNotReady`.
 *
 * So an unresolvable source answers FALSE here, deliberately, and stays with the owner it has.
 */
final readonly class DocumentSourceKind
{
    public static function isOutsideTheDraftStore(string $key): bool
    {
        $source = null;

        try {
            $source = app(LegalDocumentPublisher::class)->sourceFor($key);
        } catch (InvalidArgumentException) {
            // Not this question's case. Left exactly where it was.
        }

        // Written as an instanceof on the interface rather than `$source !== null`, and the two say
        // the same thing here only by accident of the local being initialized to null. The positive
        // form states the condition this actually needs — a source that RESOLVED and is not the
        // draft store — so it keeps reading correctly if this ever stops being a nullable local.
        return $source instanceof LegalDocumentSource && ! $source instanceof DraftDocumentSource;
    }
}
