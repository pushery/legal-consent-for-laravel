<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Closure;

/**
 * Wraps an inline closure from config into a CmsResolver, for the common case where a
 * consumer wants to plug in their CMS without writing a class. The fingerprint closure
 * is optional; without it a fingerprint is derived from the resolved document (which
 * costs a resolve, so a class with a cheap fingerprint is preferable at scale).
 */
final readonly class ClosureCmsResolver implements CmsResolver
{
    /**
     * @param  Closure(string, string): RawDocument  $resolver
     * @param  (Closure(string, string): string)|null  $fingerprint
     */
    public function __construct(
        private Closure $resolver,
        private ?Closure $fingerprint = null,
    ) {}

    public function resolve(string $type, string $locale): RawDocument
    {
        return ($this->resolver)($type, $locale);
    }

    public function fingerprint(string $type, string $locale): string
    {
        if ($this->fingerprint instanceof Closure) {
            return ($this->fingerprint)($type, $locale);
        }

        $raw = $this->resolve($type, $locale);

        return substr(hash('sha256', $raw->body.'|'.($raw->version ?? '')), 0, 32);
    }
}
