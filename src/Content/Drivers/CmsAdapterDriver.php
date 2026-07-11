<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content\Drivers;

use Pushery\LegalConsent\Content\CmsResolver;
use Pushery\LegalConsent\Content\LegalDocumentSource;
use Pushery\LegalConsent\Content\RawDocument;

/**
 * Adapts a consumer-supplied CmsResolver to the LegalDocumentSource contract, so a
 * foreign CMS can back the legal texts without the package depending on it.
 */
final readonly class CmsAdapterDriver implements LegalDocumentSource
{
    public function __construct(private CmsResolver $resolver) {}

    public function resolve(string $type, string $locale): RawDocument
    {
        return $this->resolver->resolve($type, $locale);
    }

    public function fingerprint(string $type, string $locale): string
    {
        return $this->resolver->fingerprint($type, $locale);
    }
}
