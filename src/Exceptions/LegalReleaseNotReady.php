<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

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
     * @param  array<string, string>  $blocking  locale => reason
     */
    public function __construct(
        public readonly string $key,
        public readonly array $blocking,
    ) {
        $detail = implode('; ', array_map(
            static fn (string $locale, string $reason): string => "{$locale}: {$reason}",
            array_keys($blocking),
            array_values($blocking),
        ));

        parent::__construct(
            "'{$key}' cannot be released — every locale must be publishable, or the same contract "
            ."exists in two versions at once. Blocked by — {$detail}"
        );
    }

    /**
     * @param  array<string, string>  $blocking
     */
    public static function for(string $key, array $blocking): self
    {
        return new self($key, $blocking);
    }
}
