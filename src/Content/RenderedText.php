<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

/**
 * A text rendered, sanitized and hashed the way a published document is, by
 * {@see RenderPipeline::render()}, without the version and the acceptance sentence a published
 * document carries.
 */
final readonly class RenderedText
{
    /**
     * @param  string  $html  the sanitized HTML, the form the content hash is taken over
     * @param  string  $contentHash  the SHA-256 hash over the canonicalized HTML, as on a published document
     * @param  string  $sourceHash  the SHA-256 hash over the text before rendering, its placeholders filled
     * @param  string  $renderFingerprint  the fingerprint of the renderer that produced the HTML
     */
    public function __construct(
        public string $html,
        public string $contentHash,
        public string $sourceHash,
        public string $renderFingerprint,
    ) {}
}
