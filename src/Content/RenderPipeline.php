<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use League\CommonMark\CommonMarkConverter;
use Pushery\LegalConsent\Exceptions\LegalDocumentTooLarge;

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
        $hash = hash('sha256', $this->canonicalize($html));

        $version = $raw->version ?? '0.0.0';
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
     * The exact acceptance sentence. The source's own wording wins; otherwise a
     * translated default keyed by document type. Never invents "ich willige ein" for
     * a mandatory document — that is the translator's responsibility (EDPB Rz. 122).
     */
    private function resolveWording(RawDocument $raw): string
    {
        if ($raw->uiWording !== null && trim($raw->uiWording) !== '') {
            return $raw->uiWording;
        }

        $key = "legal-consent::wording.{$raw->type}";
        $translated = trans($key);

        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        $fallback = trans('legal-consent::wording.default');

        return is_string($fallback) && $fallback !== 'legal-consent::wording.default'
            ? $fallback
            : 'Ich habe die Bedingungen gelesen und akzeptiere sie.';
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
