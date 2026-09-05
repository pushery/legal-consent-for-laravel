<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content\Drivers;

use Carbon\CarbonImmutable;
use Pushery\LegalConsent\Content\ContentFormat;
use Pushery\LegalConsent\Content\LegalDocumentSource;
use Pushery\LegalConsent\Content\RawDocument;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;

/**
 * The recommended default source: legal texts as git-diffable, PR-reviewable Markdown
 * files at `{basePath}/{type}/{locale}.md`, each with a small YAML frontmatter block:
 *
 *   ---
 *   version: "2.1.0"
 *   title: Nutzungsbedingungen
 *   material: true
 *   announce_at: 2026-01-01
 *   enforce_at: 2026-03-01
 *   ui_wording: Ich akzeptiere die Nutzungsbedingungen
 *   ---
 *   # Markdown body …
 *
 * Only a flat frontmatter is supported (no nested YAML), so no symfony/yaml dependency.
 */
final readonly class MarkdownFilesDriver implements LegalDocumentSource
{
    public function __construct(private string $basePath) {}

    public function resolve(string $type, string $locale): RawDocument
    {
        $path = $this->pathFor($type, $locale);

        if (! is_file($path)) {
            throw LegalDocumentNotFound::forSource($type, $locale, $path);
        }

        [$meta, $body] = $this->splitFrontMatter((string) file_get_contents($path));

        return new RawDocument(
            type: $type,
            locale: $locale,
            title: $meta['title'] ?? $type,
            body: $body,
            format: ContentFormat::Markdown,
            version: $meta['version'] ?? null,
            announceAt: $this->toDate($meta['announce_at'] ?? null),
            enforceAt: $this->toDate($meta['enforce_at'] ?? ($meta['effective_at'] ?? null)),
            isMaterial: $this->toBool($meta['material'] ?? null),
            uiWording: $meta['ui_wording'] ?? null,
            sourceRef: $path,
        );
    }

    public function fingerprint(string $type, string $locale): string
    {
        $path = $this->pathFor($type, $locale);

        if (! is_file($path)) {
            return 'missing';
        }

        return ((int) filemtime($path)).':'.((int) filesize($path));
    }

    private function pathFor(string $type, string $locale): string
    {
        // basename() defends against path traversal in the (config-driven, but still)
        // type/locale segments.
        return $this->basePath.'/'.basename($type).'/'.basename($locale).'.md';
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function splitFrontMatter(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $raw, $matches) === 1) {
            return [$this->parseFlatYaml($matches[1]), ltrim($matches[2])];
        }

        return [[], $raw];
    }

    /**
     * @return array<string, string>
     */
    private function parseFlatYaml(string $block): array
    {
        $out = [];

        foreach (explode("\n", $block) as $line) {
            $line = trim($line);

            // Equivalent mutant territory, and worth saying so: changing this comparison cannot be
            // observed, because the `strpos(..., ':') === false` check below catches every line
            // this one does. A blank line has no colon, so it is skipped either way.
            //
            // Kept because it says what it means -- a blank line is not a malformed entry, and
            // folding the two into "no colon" would make the parser's intent harder to read than
            // the one branch costs.
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, ':');

            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(trim(substr($line, $pos + 1)), "\"'");

            if ($key !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function toBool(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return in_array(strtolower($value), ['true', '1', 'yes'], true);
    }

    private function toDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
