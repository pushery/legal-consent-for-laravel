<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Console\Concerns\RunsPerTenant;
use Pushery\LegalConsent\Content\AwaitsAuthoring;
use Pushery\LegalConsent\Content\Document;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\ActivationLock;
use Pushery\LegalConsent\Support\CalendarDate;
use Pushery\LegalConsent\Support\DocumentMatrix;
use Pushery\LegalConsent\Support\LegalDocumentPublisher;
use Pushery\LegalConsent\Support\PublishedDocumentReader;
use Pushery\LegalConsent\Support\SourceLanguageFallback;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use ValueError;

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
 *
 * `--locales=de,en` publishes those languages of ONE key together: one transaction, one notice
 * mode, all or none. Every language of a version carries the same mode, so a change of mode
 * between two versions, `--editorial` to `--info` for example, is refused one language at a time
 * and is lawful only as a whole. The admin screens release a text on the drafts store this way;
 * this is the same release for every other source, Markdown included.
 */
#[AsCommand(name: 'legal-consent:publish')]
final class PublishDocumentCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'legal-consent:publish
        {key? : The document key (e.g. terms) — omit it and pass --all for the whole registry}
        {locale? : The locale (defaults to the configured default_locale)}
        {--locales= : Publish these locales of the key together, comma-separated (e.g. de,en): one transaction, one notice mode, all or none}
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
        {--keeps-unmodified : The subject may keep the unmodified version (DCD / §327r escape hatch)}
        {--tenant= : With tenancy on, publish into this tenant rather than the shared bucket}';

    protected $description = 'Freeze the current source text of a legal document into a new active, versioned row.';

    public function handle(LegalDocumentPublisher $publisher): int
    {
        $named = $this->namedTenant();

        if ($named === false) {
            return self::FAILURE;
        }

        $this->noteSharedBucket($named);

        return $this->inTenant($named, fn (): int => $this->publishHere($publisher));
    }

    /**
     * The publish itself, in whichever tenant is current.
     */
    private function publishHere(LegalDocumentPublisher $publisher): int
    {
        $mode = $this->resolveMode();

        if (! $mode instanceof NoticeMode) {
            $this->error('Pass exactly one change classification: --editorial, --info, --deemed, or --active (--material is the legacy alias of --active). A publisher must classify the change — there is no default.');

            return self::FAILURE;
        }

        // Each of these dates is frozen into the published row and cannot be corrected afterwards,
        // so one this command cannot read as the calendar date it names stops the run before any
        // document is touched, rather than being rolled over to a day nobody typed.
        $unreadable = $this->unreadableDateOptions();

        if ($unreadable !== []) {
            $this->error('Dates are calendar dates written as YYYY-MM-DD, and these are not: '.implode(', ', $unreadable).'. Nothing was published.');

            return self::FAILURE;
        }

        $key = $this->argument('key');
        $key = is_string($key) ? $key : '';
        $all = $this->option('all');

        if ($all === ($key !== '')) {
            $this->error('Pass either a document key or --all, not both and not neither. --all publishes every configured document in every configured locale.');

            return self::FAILURE;
        }

        // --only-missing is a modifier of the matrix run, not a second command. On a single key
        // the operator has already named the subject and looked at it, so gap-filling semantics
        // there would only hide a refusal behind a success line.
        if ($this->option('only-missing') && ! $all) {
            $this->error('--only-missing modifies --all. On a single document the publish is already deliberate; drop the flag or pass --all.');

            return self::FAILURE;
        }

        if ($all) {
            if ($this->option('locales') !== null) {
                $this->error('--locales names the languages of one key; --all already publishes every language of every key. Drop one of the two.');

                return self::FAILURE;
            }

            return $this->publishAll($publisher, $mode);
        }

        $together = $this->localesOption();

        if ($together !== null) {
            if ($this->argument('locale') !== null) {
                $this->error('Name the languages either as the locale argument or in --locales, not both.');

                return self::FAILURE;
            }

            if ($together === []) {
                $this->error('--locales needs at least one language, comma-separated: --locales=de,en.');

                return self::FAILURE;
            }

            return $this->option('dry-run')
                ? $this->previewTogether($publisher, $mode, $key, $together)
                : $this->publishTogether($publisher, $mode, $key, $together);
        }

        $locale = $this->resolveLocale();

        return $this->option('dry-run')
            ? $this->previewOne($publisher, $mode, $key, $locale)
            : $this->publishOne($publisher, $mode, $key, $locale);
    }

    private function publishOne(LegalDocumentPublisher $publisher, NoticeMode $mode, string $key, string $locale): int
    {
        $before = $this->activeVersion($key, $locale);

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

        if ($document->wasRecentlyCreated) {
            $this->reportWordingChange($key, $locale, $before, $document->ui_wording);
        }

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
     * Under --only-missing that last sentence flips FOR AN EDITORIAL SOURCE, and deliberately so.
     * A gap-filler runs from a deploy line, where a draft-backed document with no reviewed text
     * yet is not a configuration error but the normal state of an installation whose editors have
     * not written it. Failing there would make every deploy red for a reason nobody can fix from
     * the deploy. So such a source is NAMED and skipped.
     *
     * IT MUST NOT FLIP FOR EVERY SOURCE, and the reason is that the failure cannot tell two very
     * different situations apart. A markdown file missing from the repository raises the same
     * `LegalDocumentNotFound` as an unwritten draft — but nobody is going to write that one. It is
     * a deployment missing a file, and skipping it produces exactly the empty legal page this
     * command exists to prevent, behind a green deploy.
     *
     * The two are told apart by the SOURCE rather than by the failure, because the failure cannot
     * tell them apart: {@see AwaitsAuthoring} is what a source declares when its empty state is a
     * person who has not written yet. Anything else counts as provisioned and still fails.
     */
    private function publishAll(LegalDocumentPublisher $publisher, NoticeMode $mode): int
    {
        // A VALUE_NONE flag is already a bool, so this cast changes nothing. It is there for
        // previewAll()'s bool parameter, which takes no `mixed`.
        $onlyMissing = (bool) $this->option('only-missing');

        if ($this->option('dry-run')) {
            return $this->previewAll($publisher, $mode, $onlyMissing);
        }

        $published = 0;
        $unchanged = 0;
        $textless = [];
        $failures = [];
        $missing = [];

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

                $before = $this->activeVersion($key, $locale);

                try {
                    $document = $this->publish($publisher, $mode, $key, $locale);
                } catch (LegalDocumentNotFound $e) {
                    if ($this->awaitsAuthoring($publisher, $key)) {
                        $textless[] = "{$key} ({$locale}): {$e->getMessage()}";

                        continue;
                    }

                    // Decided after the run, not here: the version its reader would be shown may be
                    // the one this same run publishes a moment later.
                    $missing[] = [$key, $locale, $e->getMessage()];

                    continue;
                } catch (Throwable $e) {
                    $failures[] = "{$key} ({$locale}): {$e->getMessage()}";

                    continue;
                }

                if ($document->wasRecentlyCreated) {
                    $published++;
                    $this->info("Published {$key} ({$locale}) v{$document->version} — {$document->noticeMode()->value}.");
                    $this->reportWordingChange($key, $locale, $before, $document->ui_wording);
                } else {
                    $unchanged++;
                }
            }
        }

        $readsAnother = [];

        foreach ($missing as [$key, $locale, $message]) {
            $kept = $this->activeVersion($key, $locale);

            if ($kept instanceof LegalDocument) {
                $failures[] = "{$key} ({$locale}): {$message}".$this->keptVersion($kept);

                continue;
            }

            $standIn = $this->standInFor($key, $locale);

            if ($standIn === null) {
                $failures[] = "{$key} ({$locale}): {$message}";
            } else {
                $readsAnother[] = "{$key} ({$locale}) reads {$standIn}";
            }
        }

        // Name the unchanged count rather than printing a line per combination. On the second run
        // — the normal case in a deploy — every combination is unchanged, and a wall of "nothing
        // happened" lines trains people to stop reading the ones that matter.
        // THE "WITHOUT TEXT" SEGMENT IS UNCONDITIONAL, AND IT USED TO DEPEND ON THE FLAG.
        // The reasoning for that was sound while a textless source was a failure under the bare
        // --all: a permanently-zero count is noise, and it would move a line deploy logs are
        // grepped for. Both halves of the premise are gone — the count can be non-zero here now,
        // and a summary whose SHAPE depends on a modifier is the harder thing to grep anyway.
        $this->line("{$published} published, {$unchanged} already current, ".count($textless).' without text, '.count($failures).' failed.');

        // Skipped is not silent. A count alone would let a document sit unpublished for months
        // behind a green deploy — the same shape as the empty legal page this command exists to
        // prevent, one level up.
        foreach ($textless as $skipped) {
            $this->warn('No text yet, left unpublished: '.$skipped);
        }

        // Neither published nor failed, and deliberately outside the summary above, whose shape deploy
        // logs are searched for. A reader of this language is shown another one's version, so there
        // is no empty page here to fail on.
        foreach ($readsAnother as $entry) {
            $this->line('No text of its own, its readers get another language: '.$entry);
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
     *
     * It answers a textless source EXACTLY as the real run does, which it did not before: every
     * one of them counted as a warning and the dry run exited 0 — including under the bare --all,
     * where the run it previews fails. A preview that reports green for a run that cannot be green
     * is worse than no preview, because it is consulted precisely to avoid that red.
     *
     * The same sentence is why it resolves through {@see LegalDocumentPublisher::previewWithMode()}
     * rather than the bare renderer. The renderer answers "what text would this produce"; the
     * question an operator is actually asking is "would this publish succeed", and every guard
     * between the two — the locale, the regime, the notice mode the document type may carry, the
     * statutory advance period — used to be skipped here. The preview said "would publish" and
     * exited 0 for combinations the very next command refuses.
     */
    private function previewAll(LegalDocumentPublisher $publisher, NoticeMode $mode, bool $onlyMissing): int
    {
        $would = 0;
        $current = 0;
        $textless = 0;
        $failures = [];
        $missing = [];
        $available = [];

        foreach (DocumentMatrix::keys() as $key) {
            foreach (DocumentMatrix::locales() as $locale) {
                $active = $this->activeVersion($key, $locale);

                if ($onlyMissing && $active instanceof LegalDocument) {
                    $current++;
                    $available[] = "{$key}|{$locale}";
                    $this->line("  = {$key} ({$locale}) — active v{$active->version} kept, source not read.");

                    continue;
                }

                try {
                    $rendered = $this->preview($publisher, $mode, $key, $locale);
                } catch (LegalDocumentNotFound $e) {
                    if ($this->awaitsAuthoring($publisher, $key)) {
                        $textless++;
                        $this->warn("  ? {$key} ({$locale}) — no text yet: {$e->getMessage()}");

                        continue;
                    }

                    $missing[] = [$key, $locale, $e->getMessage()];

                    continue;
                } catch (Throwable $e) {
                    $failures[] = "{$key} ({$locale}): {$e->getMessage()}";
                    $this->error("  x {$key} ({$locale}) — {$e->getMessage()}");

                    continue;
                }

                $available[] = "{$key}|{$locale}";

                if ($this->unchanged($active, $rendered)) {
                    $current++;
                    $this->line("  = {$key} ({$locale}) — v{$rendered->version} already active, unchanged.");

                    continue;
                }

                $would++;
                $this->info($active instanceof LegalDocument
                    ? "  + {$key} ({$locale}) — would publish v{$rendered->version}, replacing active v{$active->version} (source has drifted)."
                    : "  + {$key} ({$locale}) — would publish v{$rendered->version} (first version).");
                $this->reportWordingChange($key, $locale, $active, $rendered->uiWording, preview: true);
            }
        }

        // The same decision the real run makes at its end, against what this run WOULD make active.
        foreach ($missing as [$key, $locale, $message]) {
            $kept = $this->activeVersion($key, $locale);

            if ($kept instanceof LegalDocument) {
                $failures[] = "{$key} ({$locale}): {$message}";
                $this->error("  x {$key} ({$locale}) — no text: {$message}".$this->keptVersion($kept));

                continue;
            }

            $standIn = $this->standInFor($key, $locale, $available);

            if ($standIn === null) {
                $failures[] = "{$key} ({$locale}): {$message}";
                $this->error("  x {$key} ({$locale}) — no text: {$message}");
            } else {
                $this->line("  ~ {$key} ({$locale}) — no text of its own, its readers get the {$standIn} version.");
            }
        }

        $this->line("Dry run: {$would} would publish, {$current} already current, {$textless} without text, ".count($failures).' failed. Nothing was written.');

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The language a reader of this one is shown instead, or null when there is none.
     *
     * A document that may fall back ({@see SourceLanguageFallback}) has no empty page in a language
     * without a text of its own, so a missing file there is not the configuration error this command
     * otherwise reports. It is asked at the END of a run, because the version that reader gets may be
     * the one the same run published later: a matrix listing `en` before `de` meets the missing
     * English file first.
     *
     * @param  list<string>  $available  "key|locale" a dry run would make active; a real run asks the table
     */
    private function standInFor(string $key, string $locale, array $available = []): ?string
    {
        try {
            $type = DocumentType::fromLegalBasis(DocumentMatrix::legalBasis($key));
        } catch (ValueError) {
            return null;
        }

        $default = config('legal-consent.default_locale', 'de');

        return array_find(
            SourceLanguageFallback::standInLocales($key, $type, $locale, is_string($default) && $default !== '' ? $default : 'de'),
            fn (string $candidate): bool => in_array("{$key}|{$candidate}", $available, true) || $this->hasActiveVersion($key, $candidate),
        );
    }

    /**
     * Is the source behind this document one whose empty state means nobody has written it yet?
     *
     * IT RESOLVES WITHOUT A GUARD, and a try/catch around it would be a branch no run can enter.
     * Both call sites sit inside `catch (LegalDocumentNotFound)`, the publisher never raises that
     * itself, and the only thing that does is `sources->for($key)->resolve(...)`. Arriving here
     * therefore PROVES the same key already resolved a source a moment ago, so resolving it again
     * cannot fail for a configuration reason. A misconfigured registry raises
     * `InvalidArgumentException` on the publish attempt instead and is already a failure there.
     *
     * Guarding it would leave a permanently uncovered line and, worse, imply a fallback for a case
     * that cannot happen — a reader would look for the misconfiguration it handles and find none.
     */
    private function awaitsAuthoring(LegalDocumentPublisher $publisher, string $key): bool
    {
        return $publisher->sourceFor($key) instanceof AwaitsAuthoring;
    }

    /**
     * The given languages of one key as one release: every publish inside one transaction, under
     * the lock every writer of the key's active version takes, so a refusal in one language leaves
     * none of them published.
     *
     * @param  non-empty-list<string>  $locales
     */
    private function publishTogether(LegalDocumentPublisher $publisher, NoticeMode $mode, string $key, array $locales): int
    {
        $before = [];

        foreach ($locales as $locale) {
            $before[$locale] = $this->activeVersion($key, $locale);
        }

        try {
            /** @var list<LegalDocument> $documents */
            $documents = ActivationLock::serialize(
                $key,
                'a publish of several languages',
                fn (): array => DB::transaction(fn (): array => array_map(
                    fn (string $locale): LegalDocument => $this->publish($publisher, $mode, $key, $locale, $locales),
                    $locales,
                )),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage().' Nothing was published in '.implode(', ', $locales).'.');

            return self::FAILURE;
        }

        foreach ($documents as $document) {
            $enforceFrom = $document->enforce_from?->toDateTimeString() ?? 'immediately';

            $this->info($document->wasRecentlyCreated
                ? "Published {$key} ({$document->locale}) v{$document->version} — major {$document->major_version}, {$document->noticeMode()->value}, enforced from {$enforceFrom}."
                : "Unchanged {$key} ({$document->locale}) v{$document->version} — already the active version, {$document->noticeMode()->value}.");

            if ($document->wasRecentlyCreated) {
                $this->reportWordingChange($key, $document->locale, $before[$document->locale] ?? null, $document->ui_wording);
            }
        }

        return self::SUCCESS;
    }

    /**
     * What {@see publishTogether()} would do, with the same guards and nothing written.
     *
     * @param  non-empty-list<string>  $locales
     */
    private function previewTogether(LegalDocumentPublisher $publisher, NoticeMode $mode, string $key, array $locales): int
    {
        $lines = [];

        foreach ($locales as $locale) {
            try {
                $rendered = $this->preview($publisher, $mode, $key, $locale, $locales);
            } catch (Throwable $e) {
                $this->error($e->getMessage().' The release of '.implode(', ', $locales).' would publish nothing.');

                return self::FAILURE;
            }

            $active = $this->activeVersion($key, $locale);

            $unchanged = $this->unchanged($active, $rendered);

            $lines[] = [$locale, $rendered->uiWording, $active, $unchanged, match (true) {
                $unchanged => "Dry run: {$key} ({$locale}) v{$rendered->version} is already the active version, unchanged.",
                $active instanceof LegalDocument => "Dry run: {$key} ({$locale}) would publish v{$rendered->version} as {$mode->value}, replacing active v{$active->version}.",
                default => "Dry run: {$key} ({$locale}) would publish v{$rendered->version} as {$mode->value}, the first version.",
            }];
        }

        foreach ($lines as [$locale, $wording, $active, $unchanged, $line]) {
            $this->info($line);

            if (! $unchanged) {
                $this->reportWordingChange($key, $locale, $active, $wording, preview: true);
            }
        }

        $this->line('Nothing was written.');

        return self::SUCCESS;
    }

    /**
     * The languages `--locales` names, in order and without repeats; null when the option is absent.
     *
     * @return list<string>|null
     */
    private function localesOption(): ?array
    {
        $raw = $this->option('locales');

        if (! is_string($raw)) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $locale): bool => $locale !== '')));
    }

    /**
     * One combination, resolved and reported. Writes nothing.
     *
     * Through the same guarded path as {@see previewAll}, and for the same reason: the exit code
     * has to match the run being previewed. A refusal here is reported and exits FAILURE exactly
     * as `publishOne` does, so an operator scripting `--dry-run &&` gets the answer they asked for.
     */
    private function previewOne(LegalDocumentPublisher $publisher, NoticeMode $mode, string $key, string $locale): int
    {
        try {
            $rendered = $this->preview($publisher, $mode, $key, $locale);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $active = $this->activeVersion($key, $locale);

        if ($this->unchanged($active, $rendered)) {
            $this->line("Dry run: {$key} ({$locale}) v{$rendered->version} is already the active version, unchanged. Nothing would be written.");

            return self::SUCCESS;
        }

        $this->info($active instanceof LegalDocument
            ? "Dry run: {$key} ({$locale}) would publish v{$rendered->version}, replacing active v{$active->version}. Nothing was written."
            : "Dry run: {$key} ({$locale}) would publish v{$rendered->version} as the first version. Nothing was written.");

        $this->reportWordingChange($key, $locale, $active, $rendered->uiWording, preview: true);

        return self::SUCCESS;
    }

    /**
     * Whether a run would leave the active version as it is: the same number and the same text.
     *
     * The real run looks a version up by its number. A source that raised only the number publishes
     * a new row over the same text, and under a mode that gates, asks everybody again.
     */
    private function unchanged(?LegalDocument $active, Document $rendered): bool
    {
        return $active instanceof LegalDocument
            && $active->version === $rendered->version
            && $active->content_hash === $rendered->contentHash;
    }

    /**
     * Why a language that lost its file fails even where the document may fall back.
     *
     * The read path answers a reader from their own language first ({@see PublishedDocumentReader::read()}),
     * so while a version of it is active no stand-in reaches them. The file is what the next change
     * of that text would be published from, and without it the readers keep this version for good.
     */
    private function keptVersion(LegalDocument $kept): string
    {
        return " Its readers are still shown the active v{$kept->version}, and no other language reaches them while it is active.";
    }

    private function hasActiveVersion(string $key, string $locale): bool
    {
        return $this->activeVersion($key, $locale) instanceof LegalDocument;
    }

    private function activeVersion(string $key, string $locale): ?LegalDocument
    {
        return LegalDocument::model()::query()
            // The version, the hash and the acceptance sentence are what the callers read; `id` keeps
            // the row a row rather than a bag of columns.
            ->select(['id', 'version', 'content_hash', 'ui_wording'])
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Say so when a version carries a different acceptance sentence than the one it replaces.
     *
     * The sentence is frozen into the version and copied into every consent given under it, so a
     * changed one is a change of what subjects agree to, whatever the notice mode says about the
     * text. It is a warning rather than a refusal: changing the sentence on purpose is legitimate.
     * What is not is changing it without noticing, which moving a document from Markdown to the
     * drafts store does, because the drafts store takes its sentence from the translation files
     * rather than from the front matter.
     */
    private function reportWordingChange(string $key, string $locale, ?LegalDocument $before, ?string $after, bool $preview = false): void
    {
        if (! $before instanceof LegalDocument || $before->ui_wording === $after) {
            return;
        }

        $was = $before->ui_wording ?? '(none)';
        $now = $after ?? '(none)';

        $this->warn($preview
            ? "Dry run: the acceptance sentence of {$key} ({$locale}) would change from \"{$was}\" to \"{$now}\", and every consent given under the new version would record it."
            : "The acceptance sentence of {$key} ({$locale}) changed from \"{$was}\" to \"{$now}\". Every consent given under this version records the new one.");
        $this->line("  If that is not meant, set the sentence the version should carry: `ui_wording` in a Markdown source, or lang/vendor/legal-consent/{$locale}/wording.php for the drafts store.");
    }

    /**
     * The dry run's counterpart to {@see publish}, and deliberately its MIRROR — same options, same
     * order, same names.
     *
     * That is what makes the preview answerable for the run: `--regime` and `--enforce-at` decide
     * whether a publish is refused, so a preview that quietly dropped them would report on a
     * different command than the one the operator is about to type. The guards themselves stay in
     * the publisher, because each is a legal rule and a second copy of a legal rule is a second
     * answer that drifts from the first the moment one is amended.
     *
     * @param  list<string>  $releasedTogether  the locales the previewed publish releases in one transaction
     */
    private function preview(LegalDocumentPublisher $publisher, NoticeMode $mode, string $key, string $locale, array $releasedTogether = []): Document
    {
        return $publisher->previewWithMode(
            $key,
            $locale,
            $mode,
            changeClass: $this->stringOption('change-class'),
            regime: $this->stringOption('regime'),
            announceAt: $this->dateOption('announce-at'),
            enforceAt: $this->dateOption('enforce-at'),
            objectionDeadline: $this->dateOption('objection-at'),
            // Both flags are VALUE_NONE, so these casts change nothing. They satisfy the bool
            // parameters, which take no `mixed`.
            offersTermination: (bool) $this->option('offers-termination'),
            keepsUnmodified: (bool) $this->option('keeps-unmodified'),
            releasedTogether: $releasedTogether,
        );
    }

    /**
     * @param  list<string>  $releasedTogether  the locales published in the same transaction
     */
    private function publish(LegalDocumentPublisher $publisher, NoticeMode $mode, string $key, string $locale, array $releasedTogether = []): LegalDocument
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
            // Both flags are VALUE_NONE, so these casts change nothing. They satisfy the bool
            // parameters, which take no `mixed`.
            offersTermination: (bool) $this->option('offers-termination'),
            keepsUnmodified: (bool) $this->option('keeps-unmodified'),
            releasedTogether: $releasedTogether,
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
            if ($this->option($flag)) {
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

    /**
     * A date option as the date it names, or null when it is not given. handle() has already
     * refused a value that is not a calendar date written as YYYY-MM-DD.
     */
    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? CalendarDate::parse($value) : null;
    }

    /**
     * The date options carrying a value that is not a calendar date written as YYYY-MM-DD, as
     * `--name=value`.
     *
     * @return list<string>
     */
    private function unreadableDateOptions(): array
    {
        $unreadable = [];

        foreach (['announce-at', 'enforce-at', 'objection-at'] as $name) {
            $value = $this->option($name);

            if (is_string($value) && $value !== '' && ! CalendarDate::parse($value) instanceof CarbonImmutable) {
                $unreadable[] = "--{$name}={$value}";
            }
        }

        return $unreadable;
    }
}
