<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Pushery\LegalConsent\Console\Concerns\RunsPerTenant;
use Pushery\LegalConsent\Content\Drivers\DraftDocumentSource;
use Pushery\LegalConsent\Content\LegalDocumentSource;
use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Content\SourceFactory;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Models\LegalDraft;
use Pushery\LegalConsent\Support\DocumentMatrix;
use Pushery\LegalConsent\Support\LegalDraftSet;
use Pushery\LegalConsent\Support\LegalDraftWriter;
use Pushery\LegalConsent\Support\TableLookup;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Fill the draft store from the texts another source carries, so the admin area opens on them.
 *
 * The editor works on the draft store alone and opens empty for a text that has no draft. An
 * application that wrote its legal texts as Markdown in the repository, and then moved its documents
 * onto the draft store to edit them in the admin area, needs those texts in the store once. This reads
 * each one from the source `--from` names, Markdown by default, through the pipeline and the writer the
 * editor uses, so a draft holds the bytes the editor would have stored.
 *
 * Only documents whose configured source is the draft store are filled: a draft of a document that
 * takes its text from elsewhere could never be released. The source language comes first, because a
 * translation reviewed before its source counts as stale, and the version the source text declares
 * goes onto the document. A text that has a draft already is kept, so a second run never replaces what
 * somebody edited, and a text on version 0.0.0, the version of a text nobody has written yet, is
 * skipped.
 *
 * Nothing is marked reviewed unless `--reviewed` says the texts went through review where they were
 * written, and even then a translation is marked only when its source text was written in the same
 * run: reviewing it against a source somebody has edited since would assert a match nobody checked.
 * Nothing is published either; releasing a text stays the decision of whoever works in the editor.
 */
#[AsCommand(name: 'legal-consent:draft-from-source')]
final class DraftFromSourceCommand extends Command
{
    use RunsPerTenant;

    /** Who the draft store names as the author of a draft this writes. */
    public const string ACTOR = 'import';

    /** The version of a text nobody has written yet, as the render pipeline and verify-documents read it. */
    private const string UNWRITTEN = '0.0.0';

    protected $signature = 'legal-consent:draft-from-source
        {key? : Only this document key; every document on the draft store by default}
        {--from=markdown : The configured source to read the texts from}
        {--reviewed : Mark what is written as reviewed, for texts reviewed where they were written}
        {--tenant= : With tenancy on, write into this tenant rather than the shared bucket}';

    protected $description = 'Fill the draft store from the texts another source carries, so the admin area opens on them.';

    public function handle(SourceFactory $sources, RenderPipeline $pipeline, LegalDraftWriter $writer): int
    {
        $named = $this->namedTenant();

        if ($named === false) {
            return self::FAILURE;
        }

        if (! TableLookup::exists('legal_drafts')) {
            $this->error('The draft store has no table yet. Run the migrations first.');

            return self::FAILURE;
        }

        $this->noteSharedBucket($named);

        return $this->inTenant($named, fn (): int => $this->draftHere($sources, $pipeline, $writer));
    }

    /** The run itself, in whichever tenant is current. */
    private function draftHere(SourceFactory $sources, RenderPipeline $pipeline, LegalDraftWriter $writer): int
    {
        $from = $this->option('from');
        $from = is_string($from) && $from !== '' ? $from : 'markdown';

        try {
            $source = $sources->make($from);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($source instanceof DraftDocumentSource) {
            $this->error("--from={$from} names the draft store itself. Name the source the texts are written in, such as markdown.");

            return self::FAILURE;
        }

        $keys = $this->documentsToFill($sources);

        if ($keys === null) {
            return self::FAILURE;
        }

        $sourceLocale = $this->sourceLocale();
        // The source locale first, whatever position the configured list gives it: a translation is
        // measured against the source text, so the source has to be in the store before it.
        $locales = array_values(array_unique([$sourceLocale, ...DocumentMatrix::locales()]));
        $drafted = 0;

        foreach ($keys as $key) {
            $drafted += $this->draftDocument($key, $locales, $sourceLocale, $source, $pipeline, $writer);
        }

        $this->components->info($drafted === 0 ? 'No text was drafted.' : "{$drafted} text(s) drafted. Review and release them in the admin area.");

        return self::SUCCESS;
    }

    /**
     * The documents to fill: the one the argument names, or every registered document whose
     * configured source is the draft store. Null after refusing the named one.
     *
     * @return list<string>|null
     */
    private function documentsToFill(SourceFactory $sources): ?array
    {
        $named = $this->argument('key');
        $registered = DocumentMatrix::keys();

        if (is_string($named) && $named !== '') {
            if (! in_array($named, $registered, true)) {
                $this->error("'{$named}' is not a registered document. Name a key of legal-consent.documents.");

                return null;
            }

            if (! $this->onTheDraftStore($sources, $named)) {
                $this->error("'{$named}' does not take its text from the draft store, so a draft of it could never be released. Set its source to the draft store first.");

                return null;
            }

            return [$named];
        }

        $keys = [];

        foreach ($registered as $key) {
            if ($this->onTheDraftStore($sources, $key)) {
                $keys[] = $key;
            } else {
                $this->components->twoColumnDetail($key, 'skipped, its source is not the draft store');
            }
        }

        return $keys;
    }

    /**
     * Draft one document in every locale, the source locale first, and count the texts written.
     *
     * @param  list<string>  $locales
     */
    private function draftDocument(string $key, array $locales, string $sourceLocale, LegalDocumentSource $source, RenderPipeline $pipeline, LegalDraftWriter $writer): int
    {
        $set = LegalDraftSet::for($key, $sourceLocale);
        $sourceWritten = false;
        $drafted = 0;

        foreach ($locales as $locale) {
            $label = "{$key} ({$locale})";

            if ($set->draft($locale) instanceof LegalDraft) {
                $this->components->twoColumnDetail($label, 'kept, it has a draft');

                continue;
            }

            try {
                $raw = $source->resolve($key, $locale);
            } catch (LegalDocumentNotFound) {
                $this->components->twoColumnDetail($label, 'no text in this source');

                continue;
            }

            $version = $raw->version ?? self::UNWRITTEN;

            if ($version === self::UNWRITTEN) {
                $this->components->twoColumnDetail($label, 'skipped, version 0.0.0 marks a text nobody has written yet');

                continue;
            }

            $writer->save($key, $locale, $pipeline->process($raw)->html, self::ACTOR);
            $drafted++;

            if ($locale === $sourceLocale) {
                $writer->setVersion($key, $version);
                $sourceWritten = true;
            }

            $this->components->twoColumnDetail($label, $this->review($key, $locale, $sourceWritten, $writer));
        }

        return $drafted;
    }

    /**
     * Mark a draft reviewed when `--reviewed` asks for it and the source text it is measured against
     * was written in this run, and say what happened.
     */
    private function review(string $key, string $locale, bool $sourceWritten, LegalDraftWriter $writer): string
    {
        if ($this->option('reviewed') !== true) {
            return 'drafted';
        }

        if (! $sourceWritten) {
            return 'drafted, not marked reviewed: its source text in the store was not written in this run';
        }

        $writer->markReviewed($key, $locale, self::ACTOR);

        return 'drafted and marked reviewed';
    }

    /** Whether the document takes its text from the draft store. */
    private function onTheDraftStore(SourceFactory $sources, string $key): bool
    {
        try {
            return $sources->for($key) instanceof DraftDocumentSource;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** The locale every translation is measured against, read as the draft writer reads it. */
    private function sourceLocale(): string
    {
        $locale = config('legal-consent.default_locale');

        return is_string($locale) ? $locale : 'de';
    }
}
