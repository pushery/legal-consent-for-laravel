<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Pushery\LegalConsent\Models\LegalChangeSet;
use Pushery\LegalConsent\Support\ChangeItemsAuthor;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Read, print or discard the change description for a pending version — the headless path for an
 * operator with no admin UI, and the inspectable one for CI.
 *
 * Deliberately NOT an authoring wizard. A change description is legal copy in a specific language,
 * written once and frozen forever; typing it into an interactive prompt is the wrong medium for
 * something that should be reviewed before it exists. Authoring goes through the facade, where it
 * can live in a migration, a seeder or a release script and be read in a diff.
 */
#[AsCommand(name: 'legal-consent:changes')]
final class DescribeChangeCommand extends Command
{
    protected $signature = 'legal-consent:changes
        {key : The document key, e.g. terms}
        {locale : The locale to inspect}
        {--clear : Discard the working draft for this key and locale}';

    protected $description = 'Show or discard the pending change description for a document and locale.';

    public function handle(ChangeItemsAuthor $author): int
    {
        /** @var string $key */
        $key = $this->argument('key');
        /** @var string $locale */
        $locale = $this->argument('locale');

        if ($this->option('clear')) {
            return $author->discard($key, $locale)
                ? $this->done("Discarded the draft change description for '{$key}' ({$locale}).")
                : $this->done("No draft change description for '{$key}' ({$locale}) — nothing to discard.");
        }

        $draft = $author->draft($key, $locale);

        if (! $draft instanceof LegalChangeSet) {
            $this->warn("No change description has been written for '{$key}' ({$locale}).");
            $this->line('Write one with Pushery\\LegalConsent\\Facades\\ChangeItems::for(…)->headline(…)->impact(…)->save().');

            // Not a failure. A version that owes no description is the normal case, and a non-zero
            // exit here would make the command useless for asking the question.
            return self::SUCCESS;
        }

        $this->line("<options=bold>{$key} ({$locale})</> — draft");
        $this->line('  headline: '.($draft->headline ?? '<none>'));
        $this->line('  impact:   '.($draft->impact ?? '<none>'));

        foreach ($draft->items as $item) {
            $this->line(sprintf('  [%d] %-10s %s', $item->position, $item->type->value, $item->subject));

            if (($item->detail ?? '') !== '') {
                $this->line('       '.$item->detail);
            }

            if ($item->type->namesAParty() && ! $item->describesPartyFully()) {
                // EDPB Opinion 22/2024 Rz. 22 expects who, where and what-for when a new party
                // handles personal data. Reported, never enforced: an operator may be describing a
                // clause rather than a processor, and refusing that would be the package deciding
                // what their change was about.
                $this->line('       <fg=yellow>incomplete party details (name, location, purpose)</>');
            }
        }

        if (! $draft->isAuthored()) {
            $this->newLine();
            $this->warn('This description has no headline or no impact statement. § 327r Abs. 2 Satz 2 Nr. 1 BGB wants what changed; WP260 rev.01 Rz. 31 separately wants what it means for the reader.');
        }

        return self::SUCCESS;
    }

    private function done(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }
}
