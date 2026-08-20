<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
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
 *
 * That safety holds for UNCHANGED sources, and only for those. Once a source has drifted, a second
 * `--all` run is not a no-op — it is a publication, and it carries whatever mode stands on the
 * deploy line. In practice that line reads `--editorial`, because the first run legitimately is
 * editorial; so a drifted text would be filed as the one classification that notifies NOBODY,
 * chosen by a deploy script rather than by a person. `--only-missing` is the modifier for that
 * position: it fills gaps and touches nothing that already exists, so it cannot classify a change
 * at all. Use it wherever the caller is a script; keep the bare `--all` for a human who has looked
 * at the diff.
 */
final class PublishDocumentCommand extends Command
{
    protected $signature = 'legal-consent:publish
        {key? : The document key (e.g. terms) — omit it and pass --all for the whole registry}
        {locale? : The locale (defaults to the configured default_locale)}
        {--all : Publish every configured document in every configured locale, idempotently}
        {--only-missing : With --all: publish ONLY combinations that have no active version, and leave every existing one untouched — a drifted source is named, never re-frozen}
        {--dry-run : Resolve every source and report what a run would do. Writes nothing}
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

        // --only-missing is a modifier of the matrix run, not a second command. On a single key
        // the operator has already named the subject and looked at it, so gap-filling semantics
        // there would only hide a refusal behind a success line.
        if ((bool) $this->option('only-missing') && ! $all) {
            $this->error('--only-missing modifies --all. On a single document the publish is already deliberate; drop the flag or pass --all.');

            return self::FAILURE;
        }

        if ($all) {
            return $this->publishAll($publisher, $mode);
        }

        $locale = $this->resolveLocale();

        return (bool) $this->option('dry-run')
            ? $this->previewOne($publisher, $key, $locale)
            : $this->publishOne($publisher, $mode, $key, $locale);
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
     *
     * Under --only-missing that last sentence flips, and deliberately so. A gap-filler runs from a
     * deploy line, where a draft-backed document with no reviewed text yet is not a configuration
     * error but the normal state of an installation whose editors have not written it. Failing
     * there would make every deploy red for a reason nobody can fix from the deploy. So a source
     * with no text is NAMED and skipped, and only an unexpected failure is still a failure.
     */
    private function publishAll(LegalDocumentPublisher $publisher, NoticeMode $mode): int
    {
        $onlyMissing = (bool) $this->option('only-missing');

        if ((bool) $this->option('dry-run')) {
            return $this->previewAll($publisher, $onlyMissing);
        }

        $published = 0;
        $unchanged = 0;
        $textless = [];
        $failures = [];

        foreach (DocumentMatrix::keys() as $key) {
            foreach (DocumentMatrix::locales() as $locale) {
                // The whole point of the modifier: an EXISTING active row is never looked at, so
                // a drifted source cannot be re-frozen under the mode that happens to stand on
                // the deploy line. Reporting drift is check-drift's job, and it stays sharp
                // precisely because this command does not also try to do it.
                if ($onlyMissing && $this->hasActiveVersion($key, $locale)) {
                    $unchanged++;

                    continue;
                }

                try {
                    $document = $this->publish($publisher, $mode, $key, $locale);
                } catch (LegalDocumentNotFound $e) {
                    if ($onlyMissing) {
                        $textless[] = "{$key} ({$locale}): {$e->getMessage()}";

                        continue;
                    }

                    $failures[] = "{$key} ({$locale}): {$e->getMessage()}";

                    continue;
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
        // The "without text" segment appears only where it can be non-zero. Under the bare --all a
        // textless source is a failure, so printing a permanently-zero count would be noise — and
        // it would change a line that deploy logs are grepped for, for no information.
        $this->line($onlyMissing
            ? "{$published} published, {$unchanged} already current, ".count($textless).' without text, '.count($failures).' failed.'
            : "{$published} published, {$unchanged} already current, ".count($failures).' failed.');

        // Skipped is not silent. A count alone would let a document sit unpublished for months
        // behind a green deploy — the same shape as the empty legal page this command exists to
        // prevent, one level up.
        foreach ($textless as $skipped) {
            $this->warn('No text yet, left unpublished: '.$skipped);
        }

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Resolve every combination and report what a run WOULD do. Writes nothing.
     *
     * It resolves rather than counts, which is the only version worth having: a document whose
     * markdown file is missing is reported here, in a command an operator runs on purpose, instead
     * of during the deploy that needed it.
     */
    private function previewAll(LegalDocumentPublisher $publisher, bool $onlyMissing): int
    {
        $would = 0;
        $current = 0;
        $textless = 0;
        $failures = [];

        foreach (DocumentMatrix::keys() as $key) {
            foreach (DocumentMatrix::locales() as $locale) {
                $active = $this->activeVersion($key, $locale);

                if ($onlyMissing && $active instanceof LegalDocument) {
                    $current++;
                    $this->line("  = {$key} ({$locale}) — active v{$active->version} kept, source not read.");

                    continue;
                }

                try {
                    $rendered = $publisher->preview($key, $locale);
                } catch (LegalDocumentNotFound $e) {
                    $textless++;
                    $this->warn("  ? {$key} ({$locale}) — no text: {$e->getMessage()}");

                    continue;
                } catch (Throwable $e) {
                    $failures[] = "{$key} ({$locale}): {$e->getMessage()}";
                    $this->error("  x {$key} ({$locale}) — {$e->getMessage()}");

                    continue;
                }

                if ($active instanceof LegalDocument && $active->content_hash === $rendered->contentHash) {
                    $current++;
                    $this->line("  = {$key} ({$locale}) — v{$rendered->version} already active, unchanged.");

                    continue;
                }

                $would++;
                $this->info($active instanceof LegalDocument
                    ? "  + {$key} ({$locale}) — would publish v{$rendered->version}, replacing active v{$active->version} (source has drifted)."
                    : "  + {$key} ({$locale}) — would publish v{$rendered->version} (first version).");
            }
        }

        $this->line("Dry run: {$would} would publish, {$current} already current, {$textless} without text, ".count($failures).' failed. Nothing was written.');

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * One combination, resolved and reported. Writes nothing.
     */
    private function previewOne(LegalDocumentPublisher $publisher, string $key, string $locale): int
    {
        try {
            $rendered = $publisher->preview($key, $locale);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $active = $this->activeVersion($key, $locale);

        if ($active instanceof LegalDocument && $active->content_hash === $rendered->contentHash) {
            $this->line("Dry run: {$key} ({$locale}) v{$rendered->version} is already the active version, unchanged. Nothing would be written.");

            return self::SUCCESS;
        }

        $this->info($active instanceof LegalDocument
            ? "Dry run: {$key} ({$locale}) would publish v{$rendered->version}, replacing active v{$active->version}. Nothing was written."
            : "Dry run: {$key} ({$locale}) would publish v{$rendered->version} as the first version. Nothing was written.");

        return self::SUCCESS;
    }

    private function hasActiveVersion(string $key, string $locale): bool
    {
        return $this->activeVersion($key, $locale) instanceof LegalDocument;
    }

    private function activeVersion(string $key, string $locale): ?LegalDocument
    {
        return LegalDocument::query()
            ->select(['id', 'version', 'content_hash'])
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();
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
