<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Pushery\LegalConsent\Contracts\LegalTextTranslator;
use RuntimeException;

/**
 * A translator's own answer that leaves nothing to store: its answer was cut off at one of its limits, or it
 * declined the text.
 *
 * Thrown by an implementation of {@see LegalTextTranslator}. Neither is a defect,
 * and both are something the person at the editor can act on, so the editor words them on the screen, and a
 * queued translation records them for that screen rather than failing its job. A cut-off answer must never be
 * returned as a translation instead: a draft that ends in the middle of a clause is worse than none.
 */
final class TranslationRefused extends RuntimeException
{
    /**
     * @param  bool  $truncated  whether the answer was cut off, rather than declined
     */
    public function __construct(string $message = '', public readonly bool $truncated = false)
    {
        parent::__construct($message);
    }

    /** The answer reached a limit of the translator, of its length or of its time, before the text ended. */
    public static function truncated(string $detail = ''): self
    {
        return new self(trim('The translation was cut off at a limit of the translator. '.$detail), true);
    }

    /** The translator declined to translate the text. */
    public static function declined(string $detail = ''): self
    {
        return new self(trim('The translator declined to translate the text. '.$detail));
    }
}
