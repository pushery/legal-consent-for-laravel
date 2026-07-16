<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Support\LegalDocumentPublisher;
use Throwable;

/**
 * Freeze the current source text of a legal document into a new active, versioned row.
 * The publisher MUST classify the change's notice mode — exactly one of --editorial,
 * --info, --deemed, or --active (--material is the legacy alias of --active).
 */
final class PublishDocumentCommand extends Command
{
    protected $signature = 'legal-consent:publish
        {key : The document key (e.g. terms)}
        {locale? : The locale (defaults to the configured default_locale)}
        {--material : Legacy alias of --active: a material change that forces re-consent}
        {--editorial : Editorial change — no notice, silent activation}
        {--info : Info-only change — actively announced, no action required, takes effect regardless}
        {--deemed : Deemed-consent change — silence counts as acceptance (contract/terms only)}
        {--active : Active re-consent — the subject must actively accept before it applies}
        {--change-class= : Legal-review classification tag (e.g. agb_minor_peripheral, privacy_material)}
        {--regime= : Legal regime (bgb_agb|psd2_675g|dcd_327r|gdpr|p2b|eecc)}
        {--announce-at= : When subjects are notified (ISO date)}
        {--enforce-at= : When enforcement begins (ISO date)}
        {--objection-at= : Objection deadline for a deemed-consent change (ISO date)}
        {--offers-termination : The notice offers a free right to terminate before the effective date}
        {--keeps-unmodified : The subject may keep the unmodified version (DCD / §327r escape hatch)}';

    protected $description = 'Freeze the current source text of a legal document into a new active, versioned row.';

    public function handle(LegalDocumentPublisher $publisher): int
    {
        $mode = $this->resolveMode();

        if (! $mode instanceof NoticeMode) {
            $this->error('Pass exactly one change classification: --editorial, --info, --deemed, or --active (--material is the legacy alias of --active). A publisher must classify the change — there is no default.');

            return self::FAILURE;
        }

        $key = $this->argument('key');
        $key = is_string($key) ? $key : '';
        $locale = $this->resolveLocale();

        try {
            $document = $publisher->publishWithMode(
                $key,
                $locale,
                $mode,
                changeClass: $this->stringOption('change-class'),
                regime: $this->stringOption('regime'),
                announceAt: $this->dateOption('announce-at'),
                enforceAt: $this->dateOption('enforce-at'),
                objectionDeadline: $this->dateOption('objection-at'),
                offersTermination: (bool) $this->option('offers-termination'),
                keepsUnmodified: (bool) $this->option('keeps-unmodified'),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $enforceFrom = $document->enforce_from?->toDateTimeString() ?? 'immediately';

        // Report the mode of the ROW that now exists, never the flag that was asked for — they can
        // differ (an unchanged re-publish returns the existing version), and a success line naming
        // a notice mode the row does not carry is how a missing legal notice goes unnoticed.
        $this->info("Published {$key} ({$locale}) v{$document->version} — major {$document->major_version}, {$document->noticeMode()->value}, enforced from {$enforceFrom}.");

        return self::SUCCESS;
    }

    /**
     * Exactly one mode flag must be set. --material is the backward-compatible alias of
     * --active; --editorial is the silent path.
     */
    private function resolveMode(): ?NoticeMode
    {
        $map = [
            'editorial' => NoticeMode::SilentEditorial,
            'info' => NoticeMode::InfoPush,
            'deemed' => NoticeMode::DeemedConsent,
            'active' => NoticeMode::ActiveReconsent,
            'material' => NoticeMode::ActiveReconsent,
        ];

        $selected = [];

        foreach ($map as $flag => $mode) {
            if ((bool) $this->option($flag)) {
                $selected[] = $mode;
            }
        }

        return count($selected) === 1 ? $selected[0] : null;
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

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
