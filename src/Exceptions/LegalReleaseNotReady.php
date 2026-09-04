<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Pushery\LegalConsent\Enums\BlockingReason;
use RuntimeException;

/**
 * A multi-locale release was attempted while at least one locale could not be published.
 *
 * Carries the blocking locales with their reasons, so a release screen can say WHY rather than
 * just refusing. Nothing is written when this is thrown: a release is all locales or none.
 */
final class LegalReleaseNotReady extends RuntimeException
{
    /**
     * @param  array<string, BlockingReason>  $blocking  locale => reason
     */
    public function __construct(
        public readonly string $key,
        public readonly array $blocking,
    ) {
        // No array_values() on the second argument: with MORE THAN ONE array, array_map ignores
        // every key and walks the arrays positionally, so wrapping it changes nothing. Keeping the
        // call made an equivalent mutant — one no test can ever kill, because removing it is
        // indistinguishable from leaving it. Deleting the dead call removes the mutant instead of
        // fighting it. Verified across associative, reordered, numeric-string-keyed, empty and
        // single-entry arrays: identical in all five.
        $detail = implode('; ', array_map(
            // ->value, not ->label(): this message is for a developer reading a log or a stack
            // trace, and the backing value is the same English sentence it always was. The
            // translated form belongs to the screen, which asks the enum for its label.
            static fn (string $locale, BlockingReason $reason): string => "{$locale}: {$reason->value}",
            array_keys($blocking),
            $blocking,
        ));

        parent::__construct(
            "'{$key}' cannot be released — every locale must be publishable, or the same contract "
            ."exists in two versions at once. Blocked by — {$detail}"
        );
    }

    /**
     * @param  array<string, BlockingReason>  $blocking
     */
    public static function for(string $key, array $blocking): self
    {
        return new self($key, $blocking);
    }
}
