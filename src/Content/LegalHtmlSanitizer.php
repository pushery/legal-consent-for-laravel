<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * DOM-based HTML sanitizer for legal text destined for a `{!! !!}` sink.
 *
 * A DOM parse + allowlist is used deliberately over regex passes: regex sanitizers
 * are notoriously bypassable, and this HTML can come from an untrusted CMS. Elements
 * outside the allowlist are either removed whole (dangerous: script/style/iframe/…)
 * or unwrapped (benign structural tags — their text is kept). Allowed elements keep
 * only safe attributes, and links are checked for a safe URL scheme.
 */
final class LegalHtmlSanitizer
{
    /** Formatting/semantic elements a legal document may use. */
    private const array ALLOWED = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'a', 'blockquote', 'pre', 'code', 'hr',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'span', 'sub', 'sup', 'abbr', 'mark',
    ];

    /** Removed entirely, subtree and all — active content and embeds. */
    private const array DANGEROUS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
        'textarea', 'select', 'option', 'svg', 'math', 'foreignobject', 'link',
        'meta', 'base', 'title', 'head', 'noscript', 'template', 'applet',
        'frame', 'frameset', 'img',
        // Raw-text / CDATA-content elements: their contents are parsed as literal text
        // and would otherwise round-trip live markup unescaped into the {!! !!} sink.
        'xmp', 'plaintext', 'listing', 'noembed', 'noframes',
    ];

    /** Per-tag attribute allowlist; every other attribute is stripped. */
    private const array ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
        'abbr' => ['title'],
    ];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><body>'.$html.'</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // The wrapper guarantees a body; the fallback only satisfies the type checker.
        $found = $dom->getElementsByTagName('body')->item(0);
        $body = $found instanceof DOMElement ? $found : $dom->createElement('body');

        $this->cleanChildren($body);

        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= (string) $dom->saveHTML($child);
        }

        return trim($out);
    }

    private function cleanChildren(DOMNode $parent): void
    {
        // Snapshot: cleaning mutates the child list (removals + unwraps).
        foreach (iterator_to_array($parent->childNodes) as $child) {
            $this->cleanNode($child, $parent);
        }
    }

    private function cleanNode(DOMNode $node, DOMNode $parent): void
    {
        if ($node instanceof DOMElement) {
            $this->cleanElement($node, $parent);

            return;
        }

        // Strip comments, processing instructions, and CDATA sections; keep plain text.
        // A CDATA section serializes its content VERBATIM (unescaped), so it must never
        // reach the {!! !!} sink — defense in depth on top of removing raw-text elements.
        if (
            in_array($node->nodeType, [XML_COMMENT_NODE, XML_PI_NODE, XML_CDATA_SECTION_NODE], true)
        ) {
            $parent->removeChild($node);
        }
    }

    private function cleanElement(DOMElement $element, DOMNode $parent): void
    {
        $tag = strtolower($element->tagName);

        if (in_array($tag, self::DANGEROUS, true)) {
            $parent->removeChild($element);

            return;
        }

        if (! in_array($tag, self::ALLOWED, true)) {
            $this->unwrap($element, $parent);

            return;
        }

        $this->stripAttributes($element, $tag);
        $this->cleanChildren($element);
    }

    /**
     * Replace an element with its (cleaned) children, preserving the text.
     */
    private function unwrap(DOMElement $element, DOMNode $parent): void
    {
        while ($element->firstChild instanceof DOMNode) {
            $child = $element->firstChild;
            $parent->insertBefore($child, $element);
            $this->cleanNode($child, $parent);
        }

        $parent->removeChild($element);
    }

    private function stripAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if ($name === 'href' && ! $this->isSafeUrl($attribute->nodeValue ?? '')) {
                $element->removeAttribute($attribute->nodeName);
            }
        }
    }

    /**
     * Allow relative URLs and anchors; for absolute URLs, only http/https/mailto.
     * Rejects javascript:, vbscript:, data:, file:, and control-character bypasses.
     */
    private function isSafeUrl(string $url): bool
    {
        // Strip control chars (incl. tabs/newlines used to smuggle a scheme).
        $probe = (string) preg_replace('/[\x00-\x20]+/', '', $url);

        if ($probe === '') {
            return false;
        }

        // A protocol-relative URL (`//host/path`) has no scheme yet is an ABSOLUTE cross-origin
        // navigation — it inherits the page's scheme and points off-site. Treating it as "no scheme
        // → relative → safe" would let an untrusted source slip `<a href="//phishing.example">` into
        // a binding legal text. Reject it (require an explicit http/https to leave the origin).
        //
        // Both leading chars, not just `//`: for the special (http/https) schemes a <a href> is
        // resolved under, the WHATWG URL parser normalizes `\` to `/` in the authority-delimiter
        // position, so `\\host`, `/\host` and `\/host` open the same cross-origin authority as
        // `//host`. A single leading `/` or `\` stays same-origin (relative) and is fine.
        if (isset($probe[1]) && in_array($probe[0], ['/', '\\'], true) && in_array($probe[1], ['/', '\\'], true)) {
            return false;
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $probe, $matches) === 1) {
            return in_array(strtolower(rtrim($matches[1], ':')), ['http', 'https', 'mailto'], true);
        }

        // No scheme and not protocol-relative → relative path, anchor, or query. Safe.
        return true;
    }
}
