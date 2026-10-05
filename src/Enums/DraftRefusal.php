<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

use Pushery\LegalConsent\Exceptions\LegalDocumentTooLarge;
use Pushery\LegalConsent\Exceptions\LegalDocumentUnparsable;
use Pushery\LegalConsent\Exceptions\TranslatorNotConfigured;

/**
 * Why the legal-text editor kept no text, for the status line of the person at the screen.
 *
 * Each refusal is an exception whose message is an English sentence for a log, and that sentence can
 * name a class to bind or quote the HTML parser. The editor words the refusal from its case and values
 * instead, in the language of the screen. A queued translation records the case and the values rather
 * than the message, so the page that reads the record words it the same way.
 */
enum DraftRefusal: string
{
    /** A machine translation was asked for and no translator is bound. */
    case NoTranslator = 'no_translator';

    /** The text is larger than the render pipeline takes. */
    case TooLarge = 'too_large';

    /** The HTML parser stopped before the end of the text, so the result would be a fragment. */
    case Unparsable = 'unparsable';

    public static function of(TranslatorNotConfigured|LegalDocumentTooLarge|LegalDocumentUnparsable $refusal): self
    {
        return match (true) {
            $refusal instanceof TranslatorNotConfigured => self::NoTranslator,
            $refusal instanceof LegalDocumentTooLarge => self::TooLarge,
            default => self::Unparsable,
        };
    }

    /**
     * The placeholders of the sentence for a refusal, read off the exception.
     *
     * Sizes are whole kilobytes, the text's rounded up and the limit's rounded down, so a text over
     * the limit never reads as fitting it.
     *
     * @return array<string, int>
     */
    public static function valuesOf(TranslatorNotConfigured|LegalDocumentTooLarge|LegalDocumentUnparsable $refusal): array
    {
        if (! $refusal instanceof LegalDocumentTooLarge) {
            return [];
        }

        return ['size' => (int) ceil($refusal->bytes / 1024), 'limit' => intdiv($refusal->maxBytes, 1024)];
    }

    /**
     * The translation key of the sentence that words this refusal for a person.
     *
     * The sentence continues the editor's "not saved" status line, so it starts in lower case.
     */
    public function label(): string
    {
        return 'legal-consent::ui.draft_refused_'.$this->value;
    }
}
