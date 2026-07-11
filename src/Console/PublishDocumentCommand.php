<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\LegalConsent\Support\LegalDocumentPublisher;
use Throwable;

/**
 * Freeze the current source text of a legal document into a new active, versioned row.
 * The publisher MUST classify the change: exactly one of --material / --editorial.
 */
final class PublishDocumentCommand extends Command
{
    protected $signature = 'legal-consent:publish
        {key : The document key (e.g. terms)}
        {locale? : The locale (defaults to the configured default_locale)}
        {--material : This is a material change — forces re-consent}
        {--editorial : This is an editorial change — no re-consent}
        {--announce-at= : When subjects are notified (ISO date)}
        {--enforce-at= : When enforcement begins (ISO date)}';

    protected $description = 'Freeze the current source text of a legal document into a new active, versioned row.';

    public function handle(LegalDocumentPublisher $publisher): int
    {
        $material = (bool) $this->option('material');
        $editorial = (bool) $this->option('editorial');

        if ($material === $editorial) {
            $this->error('Pass exactly one of --material or --editorial — a publisher must classify the change (there is no default).');

            return self::FAILURE;
        }

        $key = $this->argument('key');
        $key = is_string($key) ? $key : '';
        $locale = $this->resolveLocale();

        try {
            $document = $publisher->publish($key, $locale, $material, $this->dateOption('announce-at'), $this->dateOption('enforce-at'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $enforceFrom = $document->enforce_from?->toDateTimeString() ?? 'immediately';
        $class = $material ? 'material (re-consent)' : 'editorial';

        $this->info("Published {$key} ({$locale}) v{$document->version} — major {$document->major_version}, {$class}, enforced from {$enforceFrom}.");

        return self::SUCCESS;
    }

    private function resolveLocale(): string
    {
        $locale = $this->argument('locale');

        if (is_string($locale)) {
            return $locale;
        }

        $default = config('legal-consent.default_locale', 'de');

        return is_string($default) ? $default : 'de';
    }

    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
