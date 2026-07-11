<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Pushery\LegalConsent\Content\Drivers\CmsAdapterDriver;
use Pushery\LegalConsent\Content\Drivers\DatabaseDriver;
use Pushery\LegalConsent\Content\Drivers\MarkdownFilesDriver;

/**
 * Resolves the configured content source for a document key. A document declares its
 * source name (`documents.terms.source = 'markdown'`), a source declares its driver
 * (`sources.markdown.driver = MarkdownFilesDriver::class`), and this factory wires
 * them. Custom drivers (any LegalDocumentSource) are container-resolved.
 */
final readonly class SourceFactory
{
    /**
     * @param  array<string, array<string, mixed>>  $documents
     * @param  array<string, array<string, mixed>>  $sources
     */
    public function __construct(
        private Container $container,
        private array $documents,
        private array $sources,
    ) {}

    public function for(string $documentKey): LegalDocumentSource
    {
        $config = $this->documents[$documentKey] ?? null;

        if ($config === null || ! is_string($config['source'] ?? null)) {
            throw new InvalidArgumentException("No source configured for legal document '{$documentKey}'.");
        }

        $sourceName = $config['source'];

        return $this->make($sourceName);
    }

    public function make(string $sourceName): LegalDocumentSource
    {
        $config = $this->sources[$sourceName] ?? null;

        if ($config === null || ! is_string($config['driver'] ?? null)) {
            throw new InvalidArgumentException("No driver configured for source '{$sourceName}'.");
        }

        $driver = $config['driver'];

        return match ($driver) {
            MarkdownFilesDriver::class => new MarkdownFilesDriver($this->stringConfig($config, 'path', resource_path('legal'))),
            DatabaseDriver::class => new DatabaseDriver,
            CmsAdapterDriver::class => new CmsAdapterDriver($this->buildResolver($config['resolver'] ?? null)),
            default => $this->makeCustom($driver),
        };
    }

    private function makeCustom(string $driver): LegalDocumentSource
    {
        $instance = $this->container->make($driver);

        if (! $instance instanceof LegalDocumentSource) {
            throw new InvalidArgumentException("Driver '{$driver}' must implement LegalDocumentSource.");
        }

        return $instance;
    }

    private function buildResolver(mixed $resolver): CmsResolver
    {
        if ($resolver instanceof CmsResolver) {
            return $resolver;
        }

        if ($resolver instanceof Closure) {
            return new ClosureCmsResolver($resolver);
        }

        if (is_array($resolver)) {
            $resolve = $resolver['resolve'] ?? null;
            $fingerprint = $resolver['fingerprint'] ?? null;

            if (! $resolve instanceof Closure) {
                throw new InvalidArgumentException("A CMS resolver array must supply a 'resolve' closure.");
            }

            return new ClosureCmsResolver($resolve, $fingerprint instanceof Closure ? $fingerprint : null);
        }

        if (is_string($resolver)) {
            $instance = $this->container->make($resolver);

            if (! $instance instanceof CmsResolver) {
                throw new InvalidArgumentException("CMS resolver '{$resolver}' must implement CmsResolver.");
            }

            return $instance;
        }

        throw new InvalidArgumentException('The cms source needs a resolver (class-string, closure, or CmsResolver).');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function stringConfig(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
