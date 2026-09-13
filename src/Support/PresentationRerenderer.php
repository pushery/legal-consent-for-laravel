<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Content\Document;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalDocument;
use RuntimeException;

/**
 * Re-freezes a published version whose TEXT is unchanged and whose RENDERING has moved.
 *
 * The case it exists for: registering CommonMark's TableExtension turned a paragraph of pipe
 * characters into a real table, so every published document holding a table went on showing the
 * pipes — the row is append-only proof and keeps the HTML it was frozen with. The only way out was
 * a new version number written into every source file, per key and per locale, published with
 * `--editorial`. That is a materiality decision and a text edit for a change to neither.
 *
 * What happens instead: the identical text is frozen again under the next PATCH version, silently.
 *
 * ⚠️ IT IS A NEW ROW, NOT A CORRECTION OF THE OLD ONE, AND THAT IS THE POINT. Every consent in the
 * ledger names the `content_hash` it was given against, and the chain hashes it; rewriting the HTML
 * of a published version in place would leave every one of those rows pointing at a hash nothing
 * can reproduce. So the old version keeps its bytes and its proof value, and the new one carries
 * the same text in the shape it should have had.
 *
 * The proof that it IS the same text is {@see Document::$sourceHash},
 * recorded at publish since migration 000030 — never a claim by the caller, and never a comparison
 * of HTML, which differs by definition here.
 */
final readonly class PresentationRerenderer
{
    public function __construct(private LegalDocumentPublisher $publisher) {}

    /**
     * Re-freeze (key, locale), or answer NULL when there is nothing to re-freeze — no active
     * version, or one whose HTML already matches what the source renders to today.
     *
     * @throws RuntimeException when the text moved, or when the row cannot prove that it did not
     */
    public function rerender(string $key, string $locale): ?LegalDocument
    {
        $rendered = $this->publisher->preview($key, $locale);

        $active = LegalDocument::query()
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();

        if (! $active instanceof LegalDocument) {
            return null;
        }

        if ($active->source_hash === null) {
            throw new RuntimeException(
                "Cannot re-render '{$key}' ({$locale}): its active version {$active->version} was published before the source hash was recorded, so nothing here can prove the text is unchanged. Compare the text yourself and publish a new version with its materiality set."
            );
        }

        if ($active->source_hash !== $rendered->sourceHash) {
            throw new RuntimeException(
                "Cannot re-render '{$key}' ({$locale}): its source text differs from the one published as {$active->version}. That is a change of the text itself and owes a materiality decision — publish a new version instead."
            );
        }

        if ($active->content_hash === $rendered->contentHash) {
            return null;
        }

        return $this->publisher->publishWithMode(
            $key,
            $locale,
            NoticeMode::SilentEditorial,
            changeClass: 'presentation',
            prerendered: $rendered->withVersion(
                $active->major_version,
                $active->minor_version,
                $this->nextPatch($active),
            ),
        );
    }

    /**
     * The next free patch number in the active version's own major.minor line.
     *
     * Taken from the HIGHEST row rather than the active one, because they can differ: a version may
     * be published and superseded before it is ever activated, and re-using its number would meet
     * "already exists with different content" — a refusal about version numbers, raised by the one
     * command whose whole job is to pick one.
     */
    private function nextPatch(LegalDocument $active): int
    {
        $highest = LegalDocument::query()
            ->where('key', $active->key)
            ->where('locale', $active->locale)
            ->where('major_version', $active->major_version)
            ->where('minor_version', $active->minor_version)
            ->max('patch_version');

        return (is_numeric($highest) ? (int) $highest : $active->patch_version) + 1;
    }
}
