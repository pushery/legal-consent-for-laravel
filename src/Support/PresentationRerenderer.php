<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Pushery\LegalConsent\Content\AwaitsAuthoring;
use Pushery\LegalConsent\Content\Document;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
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
 * IT IS A NEW ROW, NOT A CORRECTION OF THE OLD ONE, AND THAT IS THE POINT. Every consent in the
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
        $active = LegalDocument::model()::query()
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();

        if (! $active instanceof LegalDocument) {
            return null;
        }

        try {
            $rendered = $this->publisher->preview($key, $locale);
        } catch (LegalDocumentNotFound $missing) {
            // A text still being written is the editor's state and passes as nothing to re-render.
            // A published version whose provisioned source cannot be read cannot be re-frozen, and
            // saying nothing would read as "already current".
            if ($this->publisher->sourceFor($key) instanceof AwaitsAuthoring) {
                throw $missing;
            }

            throw new RuntimeException("Cannot re-render '{$key}' ({$locale}): its active version {$active->version} has no readable source — {$missing->getMessage()}", $missing->getCode(), previous: $missing);
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

        // The source hash covers the body alone, and two fields beside it are evidence as well: the
        // title, and the acceptance sentence, which is copied into every consent given under the
        // version and folded into the hash chain. Taken from the live source, either could change
        // under "no materiality decision is owed".
        if ($active->title !== $rendered->title || $active->ui_wording !== $rendered->uiWording) {
            throw new RuntimeException(
                "Cannot re-render '{$key}' ({$locale}): its title or acceptance sentence differs from the one published as {$active->version}. A re-render freezes the same text again, and these are part of what a reader agreed to — publish a new version instead."
            );
        }

        // The effective date is the version's, never the source's: taken from the live source, it
        // fell back to now, and a scheduled major would have gated its readers at once. Before
        // that date the publisher would also measure the announcement from today and record a
        // shorter notice period than the version gave, so such a version is not re-frozen yet.
        // After it, the date is carried; the announcement of a silent re-freeze is its own.
        if ($active->enforce_from instanceof CarbonImmutable && $active->enforce_from->greaterThan(CarbonImmutable::now())) {
            throw new RuntimeException(
                "Cannot re-render '{$key}' ({$locale}) yet: its version {$active->version} takes effect on {$active->enforce_from->toDateString()}, and a re-render before then would record another notice period. Re-render once it has taken effect."
            );
        }

        return $this->publisher->publishWithMode(
            $key,
            $locale,
            NoticeMode::SilentEditorial,
            changeClass: 'presentation',
            enforceAt: $active->enforce_from,
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
        $highest = LegalDocument::model()::query()
            ->where('key', $active->key)
            ->where('locale', $active->locale)
            ->where('major_version', $active->major_version)
            ->where('minor_version', $active->minor_version)
            ->max('patch_version');

        return (is_numeric($highest) ? (int) $highest : $active->patch_version) + 1;
    }
}
