<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * Who is DECLARING a legal change, for the notice that carries it.
 *
 * § 126b BGB wants "eine lesbare Erklärung, in der die Person des Erklärenden genannt ist". The
 * package claims the durable medium in its migration and in its config, and named nobody: the
 * declarant was whatever `config('app.name')` happened to be in Laravel's mail footer. An
 * application name is not a legal person.
 *
 * Everything here is nullable and everything defaults to null, so an installation that configures
 * nothing sends exactly what it sent before — including the proof row, whose bytes must not move
 * for an unconfigured host.
 */
final readonly class NoticeIdentity
{
    public function __construct(
        public ?string $declarant = null,
        public ?string $postalAddress = null,
        public ?string $imprintUrl = null,
        public ?string $privacyUrl = null,
        public ?string $replyTo = null,
    ) {}

    /**
     * Whether a declarant was actually named. A blank string is not one — it would put an empty
     * line into an append-only proof row and read as "the declarant is unstated" in the one
     * document that exists to state it.
     */
    public function isDeclared(): bool
    {
        return $this->declarant !== null && trim($this->declarant) !== '';
    }

    /**
     * The § 126b line: the declaring person, and where to reach them. One line, because it is
     * appended to the notice body and therefore lands verbatim in the proof row's hash.
     */
    public function declarationLine(): string
    {
        $parts = array_filter([$this->declarant, $this->postalAddress], static fn (?string $part): bool => $part !== null && trim($part) !== '');

        return implode(' · ', $parts);
    }
}
