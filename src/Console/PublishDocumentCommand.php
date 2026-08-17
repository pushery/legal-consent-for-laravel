<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\DocumentMatrix;
use Pushery\LegalConsent\Support\LegalDocumentPublisher;
use Throwable;

/**
 * Freeze the current source text of a legal document into a new active, versioned row.
 * The publisher MUST classify the change's notice mode — exactly one of --editorial,
 * --info, --deemed, or --active (--material is the legacy alias of --active).
 *
 * `--all` runs the whole configured matrix — every registered document in every configured
 * locale. It exists because a fresh installation has an EMPTY `legal_documents` table, and the
 * read path deliberately does not fall back to the source: every page built on
 * `Consent::published()` renders empty, with no error, no log and no warning. The configuration is
 * correct, the sources are there, and the legal pages are shells — the one silent state this
 * package otherwise refuses to have. It matters most where the installation is CLONED (a starter
 * kit, a CI database, a fresh staging box), because then every copy begins there.
 *
 * It is safe in a deploy path, which is the point of running it there: publishing text that is
 * already the active version returns that version untouched, so a second run changes nothing.
 */
final class PublishDocumentCommand extends Command
{
    protected $signature = 'legal-consent:publish
        {key? : The document key (e.g. terms) — omit it and pass --all for the whole registry}
        {locale? : The locale (defaults to the configured default_locale)}
        {--all : Publish every configured document in every configured locale, idempotently}
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
        $all = (bool) $this->option('all');

        if ($all === ($key !== '')) {
            $this->error('Pass either a document key or --all, not both and not neither. --all publishes every configured document in every configured locale.');

            return self::FAILURE;
        }

        return $all ? $this->publishAll($publisher, $mode) : $this->publishOne($publisher, $mode, $key, $this->resolveLocale());
    }

    private function publishOne(LegalDocumentPublisher $publisher, NoticeMode $mode, string $key, string $locale): int
    {
        try {
            $document = $this->publish($publisher, $mode, $key, $locale);
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
     * The whole configured matrix, one combination at a time.
     *
     * A failure does not stop the run: a registry of ten documents with one missing translation
     * should publish the other nine and then say which one is missing, rather than leaving the
     * operator to run it repeatedly and discover the gaps one at a time. It is still a FAILURE
     * exit, because a registered document with no text is a configuration error — silently
     * skipping it recreates the empty page this command exists to prevent.
     */
    private function publishAll(LegalDocumentPublisher $publisher, NoticeMode $mode): int
    {
        $published = 0;
        $unchanged = 0;
        $failures = [];

        foreach (DocumentMatrix::keys() as $key) {
            foreach (DocumentMatrix::locales() as $locale) {
                try {
                    $document = $this->publish($publisher, $mode, $key, $locale);
                } catch (Throwable $e) {
                    $failures[] = "{$key} ({$locale}): {$e->getMessage()}";

                    continue;
                }

                if ($document->wasRecentlyCreated) {
                    $published++;
                    $this->info("Published {$key} ({$locale}) v{$document->version} — {$document->noticeMode()->value}.");
                } else {
                    $unchanged++;
                }
            }
        }

        // Name the unchanged count rather than printing a line per combination. On the second run
        // — the normal case in a deploy — every combination is unchanged, and a wall of "nothing
        // happened" lines trains people to stop reading the ones that matter.
        $this->line("{$published} published, {$unchanged} already current, ".count($failures).' failed.');

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    private function publish(LegalDocumentPublisher $publisher, NoticeMode $mode, string $key, string $locale): LegalDocument
    {
        return $publisher->publishWithMode(
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
