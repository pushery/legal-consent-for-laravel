<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Resolves where a document is READABLE, for the surfaces that display one.
 *
 * The package freezes legal texts; it does not own the pages that render them, and it must not
 * guess at a host's route names. So the host supplies `fn (LegalDocument): ?string` through
 * `legal-consent.document_url` and every surface — the registration checkboxes, the re-consent
 * gate, "Your consents" — asks this one seam.
 *
 * Why it exists at all: a consent screen that cannot open the document is silent exactly where the
 * law is loudest. Art. 7(1)/(2) GDPR want consent informed and the request intelligible, § 305
 * Abs. 2 BGB wants the terms retrievable before agreeing, and the re-consent gate is the sharpest
 * of the three, because the subject cannot continue until they agree. The failure is quiet: no test
 * goes red, the schema is intact, the row is simply no longer clickable.
 *
 * Unset stays the old behavior — a title rendered as text. A misconfigured value yields NO link
 * rather than a broken one: an empty `href` navigates to the current page, which reads as "the
 * document is here" and is worse than an absent link.
 */
final readonly class DocumentUrlResolver
{
    public function for(LegalDocument $document): ?string
    {
        $resolver = config('legal-consent.document_url');

        // A class-string is the config:cache-safe form of the same seam (a closure blocks caching),
        // so it resolves through the container exactly like the gate's subject predicate.
        $resolver = is_string($resolver) && class_exists($resolver) ? app($resolver) : $resolver;

        $url = is_callable($resolver) ? $resolver($document) : null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The same resolution for a whole set, keyed by document key — what a view needs when it
     * iterates documents and asks for one URL per row.
     *
     * @param  iterable<array-key, LegalDocument>  $documents
     * @return array<string, string>
     */
    public function keyedBy(iterable $documents): array
    {
        $urls = [];

        foreach ($documents as $document) {
            $url = $this->for($document);

            if ($url !== null) {
                $urls[$document->key] = $url;
            }
        }

        return $urls;
    }
}
