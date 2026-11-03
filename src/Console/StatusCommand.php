<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Pushery\LegalConsent\Console\Concerns\RunsPerTenant;
use Pushery\LegalConsent\Content\Drivers\DraftDocumentSource;
use Pushery\LegalConsent\Content\Drivers\MarkdownFilesDriver;
use Pushery\LegalConsent\Content\LegalDocumentSource;
use Pushery\LegalConsent\Content\SourceFactory;
use Pushery\LegalConsent\Enums\BlockingReason;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\DraftOrigin;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Enums\ReviewState;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\LegalDraft;
use Pushery\LegalConsent\Support\DocumentMatrix;
use Pushery\LegalConsent\Support\DocumentTitle;
use Pushery\LegalConsent\Support\LegalDraftSet;
use Pushery\LegalConsent\Support\TableLookup;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Every legal text by document and language, and the command that publishes it.
 *
 * Which documents exist, in which languages, how far their drafts are and which version is in force
 * were spread over the configuration, the overview screen, the doctor and the documentation of the
 * publish flags. An operator on a server, who has the shell and not the admin area, could not put a
 * publish command together without the documentation. This prints one row per document and
 * language, and under each document the command that publishes the languages that are ready, in the
 * mode of its kind, and for every other language its reason and the command that moves it on.
 *
 * It reads and writes nothing else: the commands it prints are run by whoever reads them.
 */
#[AsCommand(name: 'legal-consent:status')]
final class StatusCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'legal-consent:status
        {key? : Only this document key; every registered document by default}
        {--tenant= : With tenancy on, read this tenant rather than the shared bucket}';

    protected $description = 'List every legal text by document and language, with its heading, kind, review state and version in force, and the command that publishes it.';

    public function handle(SourceFactory $sources): int
    {
        $named = $this->namedTenant();

        if ($named === false) {
            return self::FAILURE;
        }

        return $this->inTenant($named, fn (): int => $this->statusHere($sources));
    }

    /** The table and the next steps, in whichever tenant is current. */
    private function statusHere(SourceFactory $sources): int
    {
        $keys = $this->documents();

        if ($keys === null) {
            return self::FAILURE;
        }

        if ($keys === []) {
            $this->warn('No document is registered. Register your legal texts under legal-consent.documents.');

            return self::SUCCESS;
        }

        $drafts = TableLookup::exists('legal_drafts');
        $published = TableLookup::exists('legal_documents');
        $locales = DocumentMatrix::locales();
        $rows = [];
        $next = [];

        foreach ($keys as $key) {
            $type = DocumentType::fromLegalBasis(DocumentMatrix::legalBasis($key));
            $source = $this->sourceOf($sources, $key);
            $set = $source instanceof DraftDocumentSource && $drafts ? LegalDraftSet::for($key) : null;
            $active = $published ? $this->activeVersions($key, $locales) : [];

            foreach ($locales as $locale) {
                $rows[] = [
                    $key,
                    $locale,
                    $this->heading($key, $locale, $source),
                    $type->legalBasis(),
                    $this->state($source, $set, $locale),
                    isset($active[$locale]) ? 'v'.$active[$locale] : '-',
                ];
            }

            $next[$key] = $this->nextSteps($key, $type, $locales, $source, $set, $drafts, $active);
        }

        $this->table(['Document', 'Language', 'Heading', 'Kind', 'Text', 'In force'], $rows);

        foreach ($next as $key => $lines) {
            $this->newLine();
            $this->line("  <options=bold>{$key}</>");

            foreach ($lines as $line) {
                $this->line("    {$line}");
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * The document the argument names, or every registered one; null after refusing the named one.
     *
     * @return list<string>|null
     */
    private function documents(): ?array
    {
        $named = $this->argument('key');
        $registered = DocumentMatrix::keys();

        if (! is_string($named) || $named === '') {
            return $registered;
        }

        if (! in_array($named, $registered, true)) {
            $this->error("'{$named}' is not a registered document. Name a key of legal-consent.documents: ".implode(', ', $registered).'.');

            return null;
        }

        return [$named];
    }

    /** The source the document takes its text from, or null where its configuration names none. */
    private function sourceOf(SourceFactory $sources, string $key): ?LegalDocumentSource
    {
        try {
            return $sources->for($key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The version in force per language.
     *
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private function activeVersions(string $key, array $locales): array
    {
        $versions = [];

        foreach (LegalDocument::model()::query()->select(['id', 'locale', 'version'])->where('key', $key)->whereIn('locale', $locales)->where('is_active', true)->get() as $document) {
            $versions[$document->locale] = $document->version;
        }

        return $versions;
    }

    /**
     * The heading a version of this language would carry, and a word where it would be the key.
     */
    private function heading(string $key, string $locale, ?LegalDocumentSource $source): string
    {
        if ($source instanceof MarkdownFilesDriver) {
            try {
                $title = $source->resolve($key, $locale)->title;
            } catch (Throwable) {
                return '-';
            }

            return $title === $key ? "{$key} (no title: in the front matter)" : $title;
        }

        if (! $source instanceof DraftDocumentSource) {
            return '-';
        }

        $title = DocumentTitle::for($key, $locale);

        return $title === $key && DocumentTitle::declared($key, $locale) === null ? "{$key} (no title)" : $title;
    }

    /** How far the text of one language is. */
    private function state(?LegalDocumentSource $source, ?LegalDraftSet $set, string $locale): string
    {
        if (! $source instanceof DraftDocumentSource) {
            return $source instanceof LegalDocumentSource ? 'from its source files' : 'no source configured';
        }

        if (! $set instanceof LegalDraftSet) {
            return 'the draft store has no table';
        }

        $draft = $set->draft($locale);

        if (! $draft instanceof LegalDraft) {
            return 'no draft';
        }

        if ($draft->review_state !== ReviewState::Reviewed) {
            return $draft->origin === DraftOrigin::Machine ? 'machine translation, to review' : 'to review';
        }

        if ($set->isStale($draft)) {
            return 'to review again, its source changed';
        }

        return $set->hasUnpublishedChanges($draft) ? 'reviewed, not published' : 'reviewed, in force';
    }

    /**
     * What to run next for the document: the publish command for the languages that are ready, and
     * for every other language the reason and the command that moves it on.
     *
     * @param  list<string>  $locales
     * @param  array<string, string>  $active
     * @return list<string>
     */
    private function nextSteps(string $key, DocumentType $type, array $locales, ?LegalDocumentSource $source, ?LegalDraftSet $set, bool $drafts, array $active): array
    {
        if (! $source instanceof LegalDocumentSource) {
            return ["Its source is not configured: set legal-consent.documents.{$key}.source."];
        }

        $modes = $this->modes($type, $active !== []);
        $mode = $this->flag($modes[0]);
        $choice = count($modes) > 1
            ? 'A change to a version in force takes the mode its content asks for: '.implode(', ', array_map($this->flag(...), $modes)).'.'
            : null;

        if (! $source instanceof DraftDocumentSource) {
            // A release of several languages is all or nothing, so only the languages the source holds
            // a text for are named.
            $available = array_values(array_filter($locales, static function (string $locale) use ($source, $key): bool {
                try {
                    $source->resolve($key, $locale);

                    return true;
                } catch (Throwable) {
                    return false;
                }
            }));

            if ($available === []) {
                return ['Its source holds no text in any configured language yet.'];
            }

            $lines = ["Publish: php artisan legal-consent:publish {$key} --locales=".implode(',', $available)." {$mode}"];
            $missing = array_values(array_diff($locales, $available));

            if ($missing !== []) {
                $lines[] = 'No text in its source yet: '.implode(', ', $missing).'.';
            }

            return $choice === null ? $lines : [...$lines, $choice];
        }

        if (! $drafts || ! $set instanceof LegalDraftSet) {
            return ['The draft store has no table yet. Run the migrations first.'];
        }

        $lines = [];
        $blocking = $set->blockingLocales($locales);
        $ready = array_values(array_diff($locales, array_keys($blocking)));
        $changes = $ready === [] ? [] : $set->unpublishedChanges($ready);
        $toPublish = array_values(array_filter($ready, static fn (string $locale): bool => $changes[$locale] ?? false));

        foreach ($locales as $locale) {
            if (DocumentTitle::for($key, $locale) === $key && DocumentTitle::declared($key, $locale) === null) {
                $lines[] = "{$locale}: no title, and publish refuses a heading that is only the key. Add '{$key}' => '…' to lang/vendor/legal-consent/{$locale}/titles.php.";
            }
        }

        if ($toPublish !== []) {
            $lines[] = "Publish: php artisan legal-consent:publish {$key} --locales=".implode(',', $toPublish)." {$mode}";

            if ($choice !== null) {
                $lines[] = $choice;
            }
        } elseif ($ready !== [] && $blocking === []) {
            $lines[] = 'Nothing to publish: every reviewed draft is the version in force.';
        }

        foreach ($blocking as $locale => $reason) {
            $lines[] = "{$locale}: {$reason->value}. ".$this->remedy($key, $locale, $reason);
        }

        return $lines;
    }

    /** The command that moves a language on from the reason it is held back for. */
    private function remedy(string $key, string $locale, BlockingReason $reason): string
    {
        return match ($reason) {
            BlockingReason::NoDraft => "Write it in the editor, or fill the store from the source files: php artisan legal-consent:draft-from-source {$key}",
            BlockingReason::NotReviewed, BlockingReason::StaleTranslation => "Sign it off in the editor, or: php artisan legal-consent:review {$key} --locales={$locale} --by=<who reviewed it>",
            default => 'See the overview screen for it.',
        };
    }

    /**
     * The modes a release of this document can take, the mode of its kind first. A first version is
     * released in that mode alone, as the overview releases it.
     *
     * @return non-empty-list<NoticeMode>
     */
    private function modes(DocumentType $type, bool $published): array
    {
        $material = $type->materialChangeMode();

        if (! $published || ! $type->isConsentBearing()) {
            return [$material];
        }

        $modes = [$material];

        foreach ([NoticeMode::ActiveReconsent, NoticeMode::DeemedConsent, NoticeMode::InfoPush, NoticeMode::SilentEditorial] as $mode) {
            $allowed = match ($mode) {
                NoticeMode::DeemedConsent => $type->allowsDeemedConsent(),
                NoticeMode::ActiveReconsent => $type !== DocumentType::PrivacyNotice,
                default => true,
            };

            if ($allowed && $mode !== $material) {
                $modes[] = $mode;
            }
        }

        return $modes;
    }

    /** The flag of `legal-consent:publish` that names a mode. */
    private function flag(NoticeMode $mode): string
    {
        return match ($mode) {
            NoticeMode::ActiveReconsent => '--active',
            NoticeMode::DeemedConsent => '--deemed',
            NoticeMode::InfoPush => '--info',
            NoticeMode::SilentEditorial => '--editorial',
        };
    }
}
