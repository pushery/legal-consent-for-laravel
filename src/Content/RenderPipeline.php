<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use League\CommonMark\CommonMarkConverter;
use Pushery\LegalConsent\Exceptions\InvalidDocumentVersion;
use Pushery\LegalConsent\Exceptions\LegalDocumentTooLarge;
use Pushery\LegalConsent\Exceptions\MissingAcceptanceWording;

/**
 * Turns a driver's RawDocument into a fully resolved Document — the one place where
 * rendering, sanitizing, and hashing happen ("drivers are dumb, the pipeline is smart").
 *
 * The content hash is taken over the sanitized, canonicalized HTML so a whitespace- or
 * formatting-only edit never triggers a false re-consent, while any real wording change
 * does (EDPB 05/2020 Rz. 108/110).
 */
final readonly class RenderPipeline
{
    /** Legal texts are small; anything larger is a mistake or an attack. */
    public const int MAX_BYTES = 512 * 1024;

    private CommonMarkConverter $converter;

    /**
     * @param  array<string, mixed>  $markdownConfig
     */
    public function __construct(
        private LegalHtmlSanitizer $sanitizer = new LegalHtmlSanitizer,
        array $markdownConfig = ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20],
        private int $maxBytes = self::MAX_BYTES,
    ) {
        $this->converter = new CommonMarkConverter($markdownConfig);
    }

    public function process(RawDocument $raw): Document
    {
        if (strlen($raw->body) > $this->maxBytes) {
            throw LegalDocumentTooLarge::for($raw->type, $raw->locale, strlen($raw->body), $this->maxBytes);
        }

        $rendered = $raw->format === ContentFormat::Markdown
            ? (string) $this->converter->convert($raw->body)
            : $raw->body;

        $html = $this->sanitizer->sanitize($rendered);
        $hash = $this->hashOf($html);

        $version = $raw->version ?? '0.0.0';

        // A version drives the re-consent gate (the gate compares major versions), so a
        // malformed one is refused, never normalized: `(int) 'v2'` is 0, so a 'v'-prefixed
        // version would parse to major 0 and every gate comparison `0 < 0` would be false —
        // a material change taking effect with no re-consent while the row looks compliant.
        if ($raw->version !== null && preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw InvalidDocumentVersion::for($raw->type, $raw->locale, $version);
        }

        $parts = explode('.', $version);

        return new Document(
            type: $raw->type,
            locale: $raw->locale,
            title: $raw->title,
            html: $html,
            contentHash: $hash,
            version: $version,
            majorVersion: (int) $parts[0],
            minorVersion: (int) ($parts[1] ?? 0),
            patchVersion: (int) ($parts[2] ?? 0),
            isMaterial: $raw->isMaterial ?? true,   // unknown → treat as material (safe default)
            uiWording: $this->resolveWording($raw),
            announceAt: $raw->announceAt,
            enforceAt: $raw->enforceAt,
            sourceRef: $raw->sourceRef,
        );
    }

    /**
     * The SHA-256 content hash over the sanitized, canonicalized HTML — the exact value
     * frozen into `legal_documents.content_hash` and snapshotted by the ledger. Public so a
     * frozen-row reader can re-derive it from stored bytes and prove the row is intact, using
     * the ONE canonicaliser (a second hasher anywhere would mean the ledger hashes one form
     * and the page renders another).
     */
    public function hashOf(string $html): string
    {
        return hash('sha256', $this->canonicalize($html));
    }

    /**
     * The exact acceptance sentence, always resolved in the DOCUMENT's own locale. The
     * source's own wording wins; otherwise the type-keyed translation, then the `default`
     * key — both in `$raw->locale`, never the ambient app/session locale (publishing `de`
     * from an English session must not freeze an English sentence into a German document's
     * ledger rows). Fails loud rather than substituting a hardcoded fallback in the wrong
     * language. Never invents "ich willige ein" for a mandatory document — that is the
     * translator's responsibility (EDPB Rz. 122).
     */
    private function resolveWording(RawDocument $raw): string
    {
        if ($raw->uiWording !== null && trim($raw->uiWording) !== '') {
            return $raw->uiWording;
        }

        $key = "legal-consent::wording.{$raw->type}";
        $translated = trans($key, [], $raw->locale);

        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        $default = trans('legal-consent::wording.default', [], $raw->locale);

        if (is_string($default) && $default !== 'legal-consent::wording.default') {
            return $default;
        }

        throw MissingAcceptanceWording::for($raw->type, $raw->locale);
    }

    /**
     * Normalize for hashing only (never for display): unify line endings, collapse
     * inter-tag and repeated whitespace, so a pure reformatting yields the same hash.
     */
    private function canonicalize(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = (string) preg_replace('/>\s+</', '><', $html);
        $html = (string) preg_replace('/\s+/', ' ', $html);

        return trim($html);
    }
}
