<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Pushery\LegalConsent\Console\Concerns\RunsPerTenant;
use Pushery\LegalConsent\Content\Drivers\DraftDocumentSource;
use Pushery\LegalConsent\Content\SourceFactory;
use Pushery\LegalConsent\Exceptions\LegalDraftChanged;
use Pushery\LegalConsent\Exceptions\LegalDraftNotFound;
use Pushery\LegalConsent\Models\LegalDraft;
use Pushery\LegalConsent\Support\DocumentMatrix;
use Pushery\LegalConsent\Support\LegalDraftSet;
use Pushery\LegalConsent\Support\LegalDraftWriter;
use Pushery\LegalConsent\Support\TableLookup;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Sign drafts off as reviewed from the shell, the act of the editor's "Mark as reviewed" button.
 *
 * A text on the draft store is published only once a person has signed it off, and the sign-off was
 * a button in the editor alone. An operator who works on a server without the admin area had no way
 * to give it, and an unreviewed draft refused to publish with a message that read like a missing
 * text. This signs off the languages named, after a question that names the document, the
 * languages and the version, and hands the reviewer to the sign-off the way the editor hands the
 * user who presses its button: the `LegalDraftReviewed` event carries it.
 *
 * A translation is signed off against the source text as it stands, as in the editor, so a
 * translation reviewed before its source changed counts as reviewed again once this runs over it.
 */
#[AsCommand(name: 'legal-consent:review')]
final class ReviewCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'legal-consent:review
        {key : The document key}
        {--locales= : The languages to sign off, comma-separated; every configured language whose draft waits for a review by default}
        {--by= : Who reviewed the texts, carried by the sign-off as the editor carries the user who presses its button}
        {--force : Sign off without asking first, for a run without a terminal}
        {--tenant= : With tenancy on, sign off in this tenant rather than the shared bucket}';

    protected $description = 'Sign drafts off as reviewed from the command line, after a question that names the document, the languages and the version.';

    public function handle(SourceFactory $sources, LegalDraftWriter $writer): int
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

        return $this->inTenant($named, fn (): int => $this->reviewHere($sources, $writer));
    }

    /** The sign-off itself, in whichever tenant is current. */
    private function reviewHere(SourceFactory $sources, LegalDraftWriter $writer): int
    {
        $key = $this->argument('key');

        if (! in_array($key, DocumentMatrix::keys(), true)) {
            $this->error("'{$key}' is not a registered document. Name a key of legal-consent.documents: ".implode(', ', DocumentMatrix::keys()).'.');

            return self::FAILURE;
        }

        if (! $this->onTheDraftStore($sources, $key)) {
            $this->error("'{$key}' does not take its text from the draft store, so it is reviewed where its text is written and published from there.");

            return self::FAILURE;
        }

        $locales = $this->locales();

        if ($locales === null) {
            return self::FAILURE;
        }

        $set = LegalDraftSet::for($key);
        $toReview = [];

        foreach ($locales as $locale) {
            $draft = $set->draft($locale);

            if (! $draft instanceof LegalDraft) {
                $this->components->twoColumnDetail("{$key} ({$locale})", 'no draft to review');

                continue;
            }

            if ($set->isPublishable($draft)) {
                $this->components->twoColumnDetail("{$key} ({$locale})", 'reviewed already');

                continue;
            }

            $toReview[] = $locale;
        }

        if ($toReview === []) {
            $this->components->info('Nothing waits for a review.');

            return self::SUCCESS;
        }

        $by = $this->reviewer();

        if ($by === null) {
            $this->error('Name who reviewed the texts with --by=, the way the editor names the person who presses its button.');

            return self::FAILURE;
        }

        $version = $set->version() ?? 'without a version yet';
        $question = "Sign off {$key} in ".implode(', ', $toReview)." (version {$version}) as reviewed by {$by}?";

        if ($this->option('force') !== true && ! $this->confirm($question)) {
            $this->line('Nothing was signed off.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($toReview as $locale) {
            try {
                $writer->markReviewed($key, $locale, $by);
                $this->components->twoColumnDetail("{$key} ({$locale})", "reviewed by {$by}");
            } catch (LegalDraftNotFound) {
                $failed = true;
                $this->components->twoColumnDetail("{$key} ({$locale})", 'no draft to review any more');
            } catch (LegalDraftChanged) {
                $failed = true;
                $this->components->twoColumnDetail("{$key} ({$locale})", 'written while it was signed off, so not signed off: read it again and run this once more');
            }
        }

        $this->components->info("legal-consent:status {$key} names the command that publishes it.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The languages `--locales` names, in order and without repeats, or every configured one; the
     * source language first, as the editor reviews a translation against its source. Null after
     * refusing an empty option.
     *
     * @return list<string>|null
     */
    private function locales(): ?array
    {
        $option = $this->option('locales');
        $configured = DocumentMatrix::locales();

        if (is_string($option)) {
            $named = array_values(array_unique(array_filter(array_map(trim(...), explode(',', $option)), static fn (string $locale): bool => $locale !== '')));

            if ($named === []) {
                $this->error('--locales needs at least one language, comma-separated: --locales=de,en.');

                return null;
            }

            $unknown = array_values(array_diff($named, $configured));

            if ($unknown !== []) {
                $this->error('These languages are not configured in legal-consent.locales: '.implode(', ', $unknown).'.');

                return null;
            }

            $configured = $named;
        }

        $source = config('legal-consent.default_locale');
        $source = is_string($source) ? $source : 'de';

        return in_array($source, $configured, true)
            ? array_values(array_unique([$source, ...$configured]))
            : $configured;
    }

    /** Who reviewed the texts: `--by`, or the answer to the question where a terminal can ask it. */
    private function reviewer(): ?string
    {
        $by = $this->option('by');
        $by = is_string($by) ? trim($by) : '';

        if ($by === '' && $this->input->isInteractive()) {
            $answer = $this->ask('Who reviewed the texts?');
            $by = is_string($answer) ? trim($answer) : '';
        }

        return $by === '' ? null : $by;
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
}
