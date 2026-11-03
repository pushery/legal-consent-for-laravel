<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Pushery\LegalConsent\Console\Concerns\RunsPerTenant;
use Pushery\LegalConsent\Content\AcceptanceWording;
use Pushery\LegalConsent\Content\Drivers\DraftDocumentSource;
use Pushery\LegalConsent\Content\LegalDocumentSource;
use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Content\SourceFactory;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\LegalDraft;
use Pushery\LegalConsent\Support\DocumentMatrix;
use Pushery\LegalConsent\Support\DocumentTitle;
use Pushery\LegalConsent\Support\LegalDraftSet;
use Pushery\LegalConsent\Support\LegalDraftWriter;
use Pushery\LegalConsent\Support\TableLookup;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

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
 *
 * `--refresh` is for an application that goes on writing its texts in the repository and reviews,
 * translates and releases them in the admin area. It replaces a draft with the source text when the
 * draft still says what its published version says, so a change made in the repository reaches the
 * editor. A draft whose text differs from the published version was edited in the admin area and is
 * kept and named, a draft of a text nothing is published of is kept as before, and a replaced text
 * is never marked reviewed. `--dry-run` names what a run would write and writes nothing.
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
        {--refresh : Replace a draft that still matches its published version with the text of the source}
        {--dry-run : Name what a run would draft and replace, and write nothing}
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
        $tally = ['drafted' => 0, 'replaced' => 0, 'edited' => []];

        foreach ($keys as $key) {
            $this->draftDocument($key, $locales, $sourceLocale, $source, $pipeline, $writer, $tally);
        }

        $this->summarize($tally);

        $this->proposeNames($keys, $locales, $source);

        return self::SUCCESS;
    }

    /**
     * Name the headings and acceptance sentences the source carries that the translations do not.
     *
     * The draft store takes both from the translations rather than from the draft: the sentence a
     * subject accepts is never machine-translated with the text. A Markdown source carries them in
     * its front matter, and a document the application registers itself has no line in the
     * translations until somebody writes one, so the import would leave it headed by its key and
     * asking for the generic sentence. The lines are printed ready to paste, and written nowhere.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $locales
     */
    private function proposeNames(array $keys, array $locales, LegalDocumentSource $source): void
    {
        $proposals = [];

        foreach ($keys as $key) {
            $asks = DocumentType::fromLegalBasis(DocumentMatrix::legalBasis($key))->isConsentBearing();

            foreach ($locales as $locale) {
                try {
                    $raw = $source->resolve($key, $locale);
                } catch (Throwable) {
                    continue;
                }

                if ($raw->title !== $key && trim($raw->title) !== '' && DocumentTitle::declared($key, $locale) === null) {
                    $proposals["lang/vendor/legal-consent/{$locale}/titles.php"][] = $this->catalogLine($key, $raw->title);
                }

                if ($asks && $raw->uiWording !== null && trim($raw->uiWording) !== '' && AcceptanceWording::own($key, $locale) === null) {
                    $proposals["lang/vendor/legal-consent/{$locale}/wording.php"][] = $this->catalogLine($key, $raw->uiWording);
                }
            }
        }

        if ($proposals === []) {
            return;
        }

        ksort($proposals);

        $this->newLine();
        $this->warn('The source names these texts in its front matter, and the draft store does not read it there.');
        $this->line('  It takes the heading and the acceptance sentence from the translations. Without them a');
        $this->line('  release refuses a heading that is only the key, and asks for the generic sentence.');
        $this->line('  Add them to these files, which Laravel lays over the ones this package ships:');

        foreach ($proposals as $file => $lines) {
            $this->newLine();
            $this->line("  {$file}");

            foreach ($lines as $line) {
                $this->line("      {$line}");
            }
        }

        $this->newLine();
    }

    /** One entry of a translation file, quoted the way PHP reads it back. */
    private function catalogLine(string $key, string $value): string
    {
        return $this->quoted($key).' => '.$this->quoted($value).',';
    }

    /** A single-quoted PHP string, where only a backslash and the quote itself need escaping. */
    private function quoted(string $text): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $text)."'";
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
     * Draft one document in every locale, the source locale first, and count what was written.
     *
     * @param  list<string>  $locales
     * @param  array{drafted: int, replaced: int, edited: list<string>}  $tally
     */
    private function draftDocument(string $key, array $locales, string $sourceLocale, LegalDocumentSource $source, RenderPipeline $pipeline, LegalDraftWriter $writer, array &$tally): void
    {
        $set = LegalDraftSet::for($key, $sourceLocale);
        $sourceWritten = false;
        $dryRun = $this->option('dry-run') === true;

        foreach ($locales as $locale) {
            $label = "{$key} ({$locale})";
            $draft = $set->draft($locale);

            if ($draft instanceof LegalDraft && $this->option('refresh') !== true) {
                $this->components->twoColumnDetail($label, 'kept, it has a draft');

                continue;
            }

            try {
                $raw = $source->resolve($key, $locale);
            } catch (LegalDocumentNotFound) {
                $this->components->twoColumnDetail($label, $draft instanceof LegalDraft ? 'kept, no text in this source' : 'no text in this source');

                continue;
            }

            $version = $raw->version ?? self::UNWRITTEN;

            if ($version === self::UNWRITTEN) {
                $this->components->twoColumnDetail($label, 'skipped, version 0.0.0 marks a text nobody has written yet');

                continue;
            }

            $html = $pipeline->process($raw)->html;

            if ($draft instanceof LegalDraft) {
                $kept = $this->keptOnRefresh($key, $locale, $draft, $html, $pipeline, $writer);

                if ($kept !== null) {
                    [$reason, $edited] = $kept;

                    if ($edited) {
                        $tally['edited'][] = $label;
                    }

                    $this->components->twoColumnDetail($label, $reason);

                    continue;
                }

                $tally['replaced']++;

                if ($dryRun) {
                    $this->components->twoColumnDetail($label, 'would be replaced by the text of the source');

                    continue;
                }

                // The writer resets the sign-off, and a translation measured against a replaced source
                // text counts as stale, so the language waits for a review again. It is never marked
                // reviewed here, whatever --reviewed says: nobody has seen this text in the admin area.
                $writer->save($key, $locale, $html, self::ACTOR);

                if ($locale === $sourceLocale) {
                    $writer->setVersion($key, $version);
                }

                $this->components->twoColumnDetail($label, 'replaced by the text of the source, not reviewed');

                continue;
            }

            $tally['drafted']++;

            if ($dryRun) {
                $this->components->twoColumnDetail($label, 'would be drafted');

                continue;
            }

            $writer->save($key, $locale, $html, self::ACTOR);

            if ($locale === $sourceLocale) {
                $writer->setVersion($key, $version);
                $sourceWritten = true;
            }

            $this->components->twoColumnDetail($label, $this->review($key, $locale, $sourceWritten, $writer));
        }
    }

    /**
     * Why `--refresh` keeps a draft and whether somebody edited it, or null when the source text
     * replaces it.
     *
     * A draft is replaced only while it says what its published version says. Its text is compared,
     * not its rendering: the same text rendered by a newer renderer carries another content hash and
     * the same source hash, and that is no edit. A draft of a text nothing is published of is kept, as
     * without `--refresh`, because there is no published version to tell an edit from the import by.
     *
     * @return array{0: string, 1: bool}|null
     */
    private function keptOnRefresh(string $key, string $locale, LegalDraft $draft, string $html, RenderPipeline $pipeline, LegalDraftWriter $writer): ?array
    {
        if ($writer->sameText($key, $locale, $draft, $html)) {
            return ['kept, it holds the text of the source already', false];
        }

        $active = LegalDocument::model()::query()
            ->select(['id', 'version', 'content_hash', 'source_hash'])
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();

        if (! $active instanceof LegalDocument) {
            return ['kept, nothing is published of it to compare the draft with', false];
        }

        $matches = $active->content_hash === $draft->content_hash
            || ($active->source_hash !== null && $active->source_hash === $pipeline->sourceHashOf($draft->body));

        return $matches ? null : ["kept, edited since v{$active->version} was published", true];
    }

    /**
     * What the run wrote, and the drafts `--refresh` kept because somebody edited them.
     *
     * @param  array{drafted: int, replaced: int, edited: list<string>}  $tally
     */
    private function summarize(array $tally): void
    {
        $dryRun = $this->option('dry-run') === true;
        $parts = [];

        if ($tally['drafted'] > 0) {
            $parts[] = $dryRun ? "{$tally['drafted']} text(s) would be drafted" : "{$tally['drafted']} text(s) drafted";
        }

        if ($tally['replaced'] > 0) {
            $parts[] = $dryRun ? "{$tally['replaced']} draft(s) would be replaced by their source text" : "{$tally['replaced']} draft(s) replaced by their source text";
        }

        if ($parts === []) {
            $this->components->info($dryRun ? 'Dry run: no text would be drafted. Nothing was written.' : 'No text was drafted.');
        } else {
            $this->components->info($dryRun
                ? 'Dry run: '.implode(', ', $parts).'. Nothing was written.'
                : implode(', ', $parts).'. Review and release them in the admin area.');
        }

        if ($tally['edited'] !== []) {
            $this->warn('These drafts were edited in the admin area since their published version, so they were kept: '.implode(', ', $tally['edited']).'.');
            $this->line('  Carry the change from the source over in the editor, or discard the draft there first.');
        }
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
