<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Pushery\LegalConsent\Content\Placeholders;
use RuntimeException;

/**
 * A legal text uses placeholders that {@see Placeholders} cannot fill: names it was not given, and
 * names whose value is null or blank. A text with an unfilled placeholder is never rendered, because
 * whatever is rendered may be hashed into proof.
 */
final class UnresolvedPlaceholders extends RuntimeException
{
    /**
     * @param  list<string>  $unknown  the names the text uses that were not given
     * @param  list<string>  $missing  the names that were given without a value
     */
    public function __construct(
        string $message = '',
        public readonly array $unknown = [],
        public readonly array $missing = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $unknown
     * @param  list<string>  $missing
     */
    public static function for(array $unknown, array $missing): self
    {
        $parts = [];

        if ($unknown !== []) {
            $parts[] = 'not given: '.implode(', ', $unknown);
        }

        if ($missing !== []) {
            $parts[] = 'without a value: '.implode(', ', $missing);
        }

        return new self('The legal text uses placeholders that cannot be filled ('.implode('; ', $parts).').', $unknown, $missing);
    }
}
