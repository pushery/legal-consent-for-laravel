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
 */
final readonly class RegistrationChecklistItem
{
    public function __construct(
        public string $key,
        public DocumentType $type,
        public string $title,
        public string $wording,
        public string $version,
        public string $locale,
        public bool $required,
    ) {}

    /**
     * @return array{key: string, type: string, title: string, wording: string, version: string, locale: string, required: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'title' => $this->title,
            'wording' => $this->wording,
            'version' => $this->version,
            'locale' => $this->locale,
            'required' => $this->required,
        ];
    }
}
