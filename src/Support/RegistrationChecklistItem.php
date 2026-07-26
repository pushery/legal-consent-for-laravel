<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Enums\DocumentType;

/**
 * One consent control a registration form must render, derived from what is actually PUBLISHED.
 *
 * The point is that a register form stops hardcoding its own list. A hardcoded checkbox survives
 * the document being renamed, unpublished, or published only in another language — and then either
 * blocks registration on a document that does not exist, or quietly omits one that does.
 *
 * `required` follows the legal basis and is never a UI choice: a contract or a privacy notice is
 * mandatory, a real consent is voluntary and may never be required (Art. 7(4) Kopplungsverbot).
 * `locale` is carried per item because a form may legitimately show one control in a language the
 * rest of the page is not in — the one control a visitor must understand before agreeing to it.
 *
 * LINK THE FULL TEXT WITH THIS ITEM'S `locale`, never the app locale. A mandatory document published
 * only in the default locale appears here as its default-locale control, and
 * `Consent::published($key, $locale)` has NO fallback by design (a page shows the text of the locale
 * it claims, or nothing). Passing the app locale therefore yields `null` and the visitor gets a
 * required checkbox whose text they cannot open — a clickwrap that is not informed (Art. 7(1)).
 */
final readonly class RegistrationChecklistItem
{
    public function __construct(
        public string $key,
        public ?DocumentType $type,
        public string $title,
        public string $wording,
        public string $version,
        public string $locale,
        public bool $required,
        // The render-time acceptance fingerprint of this document, for the OPT-IN accept-time guard
        // (see hashField()). Empty for an attestation, which has no version to freeze.
        public string $contentHash = '',
    ) {}

    /**
     * The form field this control must be named, so a form built from the checklist validates
     * against the rules without the consumer guessing the convention.
     *
     * A document control is `legal_{key}`; an ATTESTATION (no document, hence no type — today the
     * Art. 8 age gate) is named by its key directly.
     */
    public function field(): string
    {
        return $this->type instanceof DocumentType ? "legal_{$this->key}" : $this->key;
    }

    /**
     * The hidden field a form renders to ACTIVATE the accept-time content-hash guard for this
     * document: it carries {@see $contentHash} (the render-time fingerprint), and
     * RegistrationConsentRecorder passes it to `accept()` so a version released mid-form is caught
     * (a 409) instead of silently frozen. Named `{field}_hash`.
     *
     * Rendering it is OPT-IN and adds no behavior by itself: a form that omits it keeps the prior
     * no-guard registration path (the recorder simply receives no expected hash). Empty for an
     * attestation, which has no document to guard.
     */
    public function hashField(): string
    {
        return $this->type instanceof DocumentType ? "legal_{$this->key}_hash" : '';
    }

    /**
     * @return array{key: string, type: string|null, title: string, wording: string, version: string, locale: string, required: bool, field: string, contentHash: string, hashField: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type?->value,
            'title' => $this->title,
            'wording' => $this->wording,
            'version' => $this->version,
            'locale' => $this->locale,
            'required' => $this->required,
            'field' => $this->field(),
            'contentHash' => $this->contentHash,
            'hashField' => $this->hashField(),
        ];
    }
}
