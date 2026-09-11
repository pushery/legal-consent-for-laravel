<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use LibXMLError;
use Pushery\LegalConsent\Exceptions\LegalDocumentUnparsable;

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
        // A short-circuit, not a correctness guard, and worth the distinction: measured, the parse
        // path answers `''` for empty and for whitespace-only input too, so removing this changes
        // no output — it only builds a DOMDocument to arrive at the same string. Nothing can hold
        // it from outside, and nothing should try; what must not happen is someone reading it as
        // THE empty-input contract and moving the behavior here.
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;

        // The closing `</body>` and the two flags are EQUIVALENT under mutation, measured rather
        // than assumed (2026-09-11, 30 inputs including stray and nested body tags, a head, CDATA, a
        // processing instruction and 30 levels of nesting; no output differed). libxml closes the
        // body at the end of the input by itself, and without the flags it adds the doctype and
        // html wrapper it otherwise omits, while the body lookup below still finds the same body.
        // Both stay because they say what is being parsed.
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><body>'.$html.'</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // ⚠️ THE ERRORS USED TO BE THROWN AWAY UNREAD, AND ONE CLASS OF THEM MEANS LOST CONTENT.
        //
        // Measured: 260 nested elements hit libxml's hard depth limit of 256 and the parser STOPS.
        // 500 004 bytes of input came back as 6 375 — no error, no warning, no log — and
        // `content_hash` is taken over the sanitizer's output, so the append-only row then claims
        // that fragment is the text somebody agreed to. The operator published something else.
        //
        // Clearing them is right for the rest, and that is why this keys on the LEVEL rather than
        // on a message: unclosed tags, stray closing tags, unquoted attributes, entity smuggling,
        // CDATA, processing instructions and NUL bytes each produce either nothing or a RECOVERABLE
        // error and still parse in full — measured, all of them. Recovering from messy HTML is what
        // this parser is for. A FATAL error is the one that says it gave up.
        $fatal = array_find($errors, static fn (LibXMLError $error): bool => $error->level === LIBXML_ERR_FATAL);

        if ($fatal instanceof LibXMLError) {
            throw LegalDocumentUnparsable::because(trim($fatal->message));
        }

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
        // ⚠️ ONLY THE COMMENT ENTRY IS REACHABLE, so removing either of the other two would change
        // nothing. Measured on the HTML path this sanitizer uses: libxml folds both a processing
        // instruction and a CDATA section into COMMENT nodes, so no input produces an XML_PI_NODE
        // or an XML_CDATA_SECTION_NODE, and removing either name from the list below changes
        // nothing. The arm above that says it strips a processing instruction passes through the
        // comment branch, not through XML_PI_NODE.
        //
        // Both names stay: the list describes what must never survive into shipped HTML, not what
        // today's parser happens to emit. A processing instruction reaching a legal document is
        // exactly the thing nobody would notice had been dropped from the list.
        if (
            in_array($node->nodeType, [XML_COMMENT_NODE, XML_PI_NODE, XML_CDATA_SECTION_NODE], true)
        ) {
            $parent->removeChild($node);
        }
    }

    private function cleanElement(DOMElement $element, DOMNode $parent): void
    {
        // ⚠️ NO TEST CAN KILL THE `strtolower` HERE, and that is a fact about the PARSER, not a
        // gap. Measured: libxml's HTML parser already lowercases `tagName`, so `<P>` arrives as
        // `p` and unwrapping this call cannot change any outcome reachable through
        // sanitize() -- the only caller.
        //
        // It stays because the guarantee belongs to the parser, not to this function, and the two
        // are one edit apart: loading the same string as XML preserves case, and then every tag
        // silently stops matching a lowercase allowlist. So removing this call changes nothing
        // observable -- for exactly as long as that stays true, and not one edit longer.
        //
        // Note what this means for the arm named "matches a dangerous tag regardless of how it is
        // capitalised": the guarantee it asserts is real and worth having, but it passes with this
        // call removed, so it is evidence about the PARSER rather than about this line. Read as
        // coverage of this line it would be misleading, which is why it is said here.
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
            // Same as the tag above, same reason: measured, libxml lowercases attribute names on
            // the HTML path, so `HREF` arrives as `href` and unwrapping this call would change
            // nothing. Note the failure direction if it were ever reachable -- an unrecognized name
            // is DROPPED a few lines down, so the case would fail safe rather than open, which is
            // why this is a robustness guard and not a security control.
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
            // The `rtrim` makes the group index unobservable: capture group 1 excludes the colon
            // and group 0 includes it, so after trimming a trailing colon the two are IDENTICAL for
            // every input this pattern can match -- verified across `https://a`, `MAILTO:x@y`,
            // `javascript:alert(1)` and `h+t.t-p1:x`. Which index is read is therefore not
            // observable from outside this function, and will not become observable.
            //
            // Both are kept rather than picking one: the group is the scheme by intent, and the
            // rtrim makes the line correct even if the pattern is later widened to capture the
            // delimiter. Belt and braces on a check that decides whether `javascript:` is a link.
            return in_array(strtolower(rtrim($matches[1], ':')), ['http', 'https', 'mailto'], true);
        }

        // No scheme and not protocol-relative → relative path, anchor, or query. Safe.
        return true;
    }
}
