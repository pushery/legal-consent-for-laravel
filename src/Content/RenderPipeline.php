<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Composer\InstalledVersions;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Exceptions\InvalidDocumentVersion;
use Pushery\LegalConsent\Exceptions\LegalDocumentTooLarge;
use Pushery\LegalConsent\Exceptions\MissingAcceptanceWording;
use Pushery\LegalConsent\Support\LegalDriftChecker;

/**
 * Turns a driver's RawDocument into a fully resolved Document — the one place where
 * rendering, sanitizing, and hashing happen ("drivers are dumb, the pipeline is smart").
 *
 * The content hash is taken over the sanitized, canonicalized HTML so a whitespace- or
 * formatting-only edit never triggers a false re-consent, while any real wording change
 * does (EDPB 05/2020 Rz. 108/110).
 *
 * Two more values leave here with every document: a hash of the SOURCE text, taken before any
 * rendering decision touches it, and a fingerprint of the renderer that produced the HTML. They
 * are what lets `legal-consent:check-drift` answer the question the content hash alone cannot —
 * did the TEXT move, or only the way it is presented.
 */
final readonly class RenderPipeline
{
    /** Legal texts are small; anything larger is a mistake or an attack. */
    public const int MAX_BYTES = 512 * 1024;

    /**
     * The markdown settings this package renders with, and the value the shipped config file
     * declares. They are applied per key, under whatever array the pipeline is given.
     *
     * The provider merges the shipped configuration under a published one recursively, so a
     * published `'markdown' => ['max_nesting_level' => 10]` receives the other two keys. A
     * configuration cached before an update is not merged at all, though, and a pipeline built by
     * hand receives exactly the array it was handed. Handing such an array to CommonMark unmerged
     * would fall back to its defaults for the keys that are missing — `html_input: allow`,
     * `allow_unsafe_links: true`, no nesting cap. The sanitizer still holds the security line, so
     * the visible damage is subtler and permanent: different HTML, therefore a different
     * `content_hash`, therefore a drift report on a text nobody edited and a fresh version of an
     * unchanged document.
     *
     * @var array<string, mixed>
     */
    public const array MARKDOWN_DEFAULTS = [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
        'max_nesting_level' => 20,
    ];

    /**
     * The extensions the converter registers, in order.
     *
     * A list rather than two `addExtension()` calls because {@see self::fingerprint()} reads it:
     * an extension that is registered without appearing here would change the HTML of every
     * published text while claiming the renderer had not moved, which is the one lie this
     * fingerprint exists to prevent.
     *
     * @var list<class-string<ExtensionInterface>>
     */
    private const array EXTENSIONS = [
        CommonMarkCoreExtension::class,
        TableExtension::class,
    ];

    private MarkdownConverter $converter;

    /**
     * The markdown settings this instance actually renders with — the defaults plus whatever the
     * application overrode. Kept because the fingerprint has to state the settings that produced
     * a row, not the ones the package ships.
     *
     * @var array<string, mixed>
     */
    private array $markdown;

    /**
     * @param  array<string, mixed>  $markdownConfig  overrides, per key, on top of
     *                                                {@see self::MARKDOWN_DEFAULTS}
     * @param  array<string, mixed>  $documents  the `legal-consent.documents` registry, so the
     *                                           pipeline can tell whether a key binds anyone.
     *                                           An empty registry means every key defaults to
     *                                           `contract`, which is the behavior every caller
     *                                           had before this argument existed.
     */
    public function __construct(
        private LegalHtmlSanitizer $sanitizer = new LegalHtmlSanitizer,
        array $markdownConfig = [],
        private int $maxBytes = self::MAX_BYTES,
        private array $documents = [],
    ) {
        // TABLES ARE REGISTERED, AND THE SANITIZER IS WHY THIS IS A FIX RATHER THAN A FEATURE.
        // Its allowlist has permitted `table`, `thead`, `tbody`, `tr`, `th` and `td` all along —
        // it was describing a capability the converter never had. A Markdown table in a legal text
        // came out as a paragraph full of pipe characters, and nothing went red: the sanitizer
        // would have passed the tags, the converter simply never produced them.
        //
        // That lands on exactly the wrong text type. A privacy notice or a cookie policy is the
        // document that lists recipients, purposes and retention periods in a table, and a
        // consumer only sees it on the rendered page.
        //
        // TableExtension alone, not GithubFlavoredMarkdownExtension: the latter also brings
        // autolinking, strikethrough and task lists, and turning three unrelated behaviors on
        // while fixing one is how a rendering surface changes underneath texts that are hashed
        // into append-only proof rows.
        $this->markdown = array_merge(self::MARKDOWN_DEFAULTS, $markdownConfig);

        $environment = new Environment($this->markdown);

        foreach (self::EXTENSIONS as $extension) {
            $environment->addExtension(new $extension);
        }

        $this->converter = new MarkdownConverter($environment);
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
        if ($raw->version !== null && preg_match('/^\d+\.\d+\.\d+\z/', $version) !== 1) {
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
            // The two `?? 0` cannot fire: a version is either the '0.0.0' default or has passed
            // /^\d+\.\d+\.\d+\z/ above, so all three parts exist. They stay for the type checker,
            // which cannot see the pattern.
            minorVersion: (int) ($parts[1] ?? 0),
            patchVersion: (int) ($parts[2] ?? 0),
            isMaterial: $raw->isMaterial ?? true,   // unknown → treat as material (safe default)
            uiWording: $this->wordingFor($raw),
            announceAt: $raw->announceAt,
            enforceAt: $raw->enforceAt,
            sourceRef: $raw->sourceRef,
            sourceHash: $this->sourceHashOf($raw->body),
            renderFingerprint: $this->fingerprint(),
        );
    }

    /**
     * The SHA-256 hash over the SOURCE text, taken before a single rendering decision touches it.
     *
     * Frozen onto the published row next to the content hash, it is the proof that answers "did
     * anybody edit this?" on its own. Without it a drift report can only say that the HTML differs,
     * and the operator is sent into a materiality decision about a text nobody changed — which is
     * what happened to every document holding a table when TableExtension was registered.
     *
     * THE CANONICALIZATION IS DELIBERATELY NARROWER THAN {@see self::canonicalize()}, AND THE
     * DIFFERENCE IS LOAD-BEARING. In HTML, whitespace between tags carries nothing; in Markdown it
     * carries meaning — four leading spaces are a code block, two trailing ones are a hard line
     * break. Collapsing runs of whitespace here would hide a real rendering change behind an
     * unchanged source hash and have drift report "presentation only" over an edit that moved the
     * text. So only the two things that never mean anything are normalized: the line endings a
     * checkout decides, and the final newline an editor decides.
     */
    public function sourceHashOf(string $body): string
    {
        return hash('sha256', rtrim(str_replace(["\r\n", "\r"], "\n", $body), "\n"));
    }

    /**
     * A fingerprint over HOW this pipeline renders: the markdown options in effect, the extensions
     * it registers, the sanitizer's allowlists, and the installed CommonMark version.
     *
     * Frozen onto every published row, it is the second half of the drift answer. When the HTML of
     * an untouched text changes, this is the value that says the RENDERER moved — and when the
     * text changed as well, it is what lets the report name both without conflating them.
     *
     * It states what the renderer WAS, and only over what is listed above. It cannot see a
     * change inside the sanitizer's own traversal, or inside CommonMark at a version it already
     * had. {@see LegalDriftChecker} reports that residue as the
     * open case it is instead of attributing it to the text.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'markdown' => $this->markdown,
            'extensions' => self::EXTENSIONS,
            'sanitizer' => $this->sanitizer->fingerprint(),
            // `??` rather than a guarded call: league/commonmark is a hard requirement of this
            // package, so the null side is unreachable in an installed tree — it is here because
            // the signature allows it, not because a run can take it.
            'commonmark' => InstalledVersions::getVersion('league/commonmark') ?? 'unknown',
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The SHA-256 content hash over the sanitized, canonicalized HTML — the exact value
     * frozen into `legal_documents.content_hash` and snapshotted by the ledger. Public so a
     * frozen-row reader can re-derive it from stored bytes and prove the row is intact, using
     * the ONE canonicalizer (a second hasher anywhere would mean the ledger hashes one form
     * and the page renders another).
     */
    public function hashOf(string $html): string
    {
        return hash('sha256', $this->canonicalize($html));
    }

    /**
     * The acceptance sentence for this document — or NULL when the document asks the reader
     * for nothing at all.
     *
     * `informational` is the whole reason this method sits in front of resolveWording(). An
     * Impressum (§ 5 DDG), a cookie policy or an accessibility statement is published and
     * kept current, and binds nobody. It has no acceptance sentence, and there is no honest
     * value to invent for one: every candidate is either an untruth or a placeholder — and
     * ui_wording is proof. It is not in MUTABLE_AFTER_PUBLISH, the BEFORE UPDATE trigger
     * refuses to change it, and it is copied verbatim into every ledger row and folded into
     * the hash chain. Whatever lands here is permanent.
     *
     * Before this branch existed the chain ran source -> `wording.{key}` -> `wording.default`,
     * and since no `informational` key is registered anywhere, an Impressum froze
     * "Ich habe die Bedingungen gelesen und akzeptiere sie." — a statement nobody made, in the
     * one column this package builds its evidentiary weight on.
     *
     * A wording SUPPLIED by the source is dropped here rather than carried, and that is
     * deliberate: carrying it is exactly the defect. An operator who wrote `ui_wording` into
     * an informational page's frontmatter has misunderstood the class, and honoring it would
     * freeze the misunderstanding.
     */
    private function wordingFor(RawDocument $raw): ?string
    {
        return $this->typeFor($raw->type)->isConsentBearing()
            ? $this->resolveWording($raw)
            : null;
    }

    /**
     * The document class the registry declares for this key.
     *
     * Defaults to `contract` for an unregistered key, matching LegalDocumentPublisher: the
     * conservative direction, since a contract IS consent-bearing and therefore still demands
     * a sentence. A default of `informational` would silently strip the acceptance sentence
     * off every document a caller forgot to register — the failure pointing the other way, and
     * the far more expensive one.
     */
    private function typeFor(string $key): DocumentType
    {
        $entry = $this->documents[$key] ?? null;
        $basis = is_array($entry) ? ($entry['legal_basis'] ?? 'contract') : 'contract';

        return DocumentType::fromLegalBasis(is_string($basis) ? $basis : 'contract');
    }

    /**
     * The exact acceptance sentence, always resolved in the DOCUMENT's own locale. The
     * source's own wording wins; otherwise the type-keyed translation, then the `default`
     * key — both in `$raw->locale` or its language, never the ambient app/session locale and
     * never `app.fallback_locale` (publishing `de` from an English session, or `pl` in an
     * application falling back to English, must not freeze an English sentence into the
     * document's ledger rows). Fails loud rather than substituting a sentence in the wrong
     * language. Never invents "ich willige ein" for a mandatory document — that is the
     * translator's responsibility (EDPB Rz. 122).
     */
    private function resolveWording(RawDocument $raw): string
    {
        if ($raw->uiWording !== null && trim($raw->uiWording) !== '') {
            return $raw->uiWording;
        }

        // The translations are read in the document's language only, never through
        // `app.fallback_locale`, and an empty line counts as none ({@see AcceptanceWording}).
        // ui_wording is frozen proof: it is NOT in MUTABLE_AFTER_PUBLISH, the BEFORE UPDATE
        // trigger rejects changing it, and it is copied verbatim into every ledger row and folded
        // into the hash chain. A sentence in another language, or an empty one, would be
        // permanent — in the document AND in every consent that points at it. Failing loud is the
        // whole point: never publish a sentence the subject could not read, or a nameless consent.
        return AcceptanceWording::for($raw->type, $raw->locale)
            ?? throw MissingAcceptanceWording::for($raw->type, $raw->locale);
    }

    /**
     * Normalize for hashing only (never for display): unify line endings, collapse
     * inter-tag and repeated whitespace, so a pure reformatting yields the same hash.
     */
    private function canonicalize(string $html): string
    {
        // Changing no hash on its own: both patterns below treat `\r` and `\n` as `\s`, so every
        // run of line endings collapses to one space with or without this line. It stays because
        // it states the intent.
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = (string) preg_replace('/>\s+</', '><', $html);
        $html = (string) preg_replace('/\s+/', ' ', $html);

        return trim($html);
    }
}
