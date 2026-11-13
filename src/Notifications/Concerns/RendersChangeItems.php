<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;
use Pushery\LegalConsent\Models\LegalChangeItem;
use Pushery\LegalConsent\Models\LegalChangeSet;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\ChangeItemsAuthor;
use Pushery\LegalConsent\Support\FreeTermination;
use Pushery\LegalConsent\Support\MarkdownText;

/**
 * Renders the operator's description of a change into a notice — what every real change notice
 * leads with and what a fixed per-type sentence can never say.
 *
 * As lines on the MailMessage, never as a replacement view. That is the load-bearing choice: the
 * durable-medium proof row is built from `subject + introLines + actionText + outroLines`, so a
 * delta rendered into lines lands in `notice_body` and its sha256 automatically, while a delta
 * rendered by swapping the view would be visible to the subject and absent from the record of what
 * they were shown.
 *
 * Nothing here is required. A version with no description renders exactly the notice it rendered
 * before, byte for byte — the feature has to be worth turning on, not unavoidable.
 *
 * Everything the operator typed is set in as text, through MarkdownText: the headline, the impact,
 * and each field of an entry. The mail is Markdown, so a party contact typed as an address or a
 * detail written as a link would reach every recipient as a link. The proof is meant to show what
 * the recipient read, and Markdown in a description would show what a parser made of it, so a
 * description keeps its characters and loses its formatting.
 */
trait RendersChangeItems
{
    private ?LegalChangeSet $changeSet = null;

    private bool $changeSetLoaded = false;

    /**
     * Append the description, if the version has one. Returns the same message for chaining.
     */
    protected function addChangeItems(MailMessage $mail, LegalDocument $document): MailMessage
    {
        $set = $this->changeSetFor($document);

        if (! $set instanceof LegalChangeSet) {
            return $mail;
        }

        $headline = $set->headline ?? '';

        if ($headline !== '') {
            $mail->line(MarkdownText::text($headline));
        }

        // The entries as one list, so they read as one block rather than a paragraph each. One line of
        // the mail: an HtmlString keeps its line breaks, where MailMessage joins those of a string.
        $entries = [];

        foreach ($set->items as $item) {
            $entries[] = $this->renderItem($item)->toHtml();
        }

        if ($entries !== []) {
            $mail->line(new HtmlString(implode("\n", $entries)));
        }

        // Impact after the list. § 327r Abs. 2 Satz 2 Nr. 1 BGB wants the characteristics of the
        // change and WP260 rev.01 Rz. 31 separately wants its likely effect — a reader who has just
        // seen what moved is the one who can use the second sentence.
        $impact = $set->impact ?? '';

        if ($impact !== '') {
            $mail->line(MarkdownText::text($impact));
        }

        return $mail;
    }

    /**
     * Does any entry make the change disadvantageous? A notice that takes something away owes the
     * exit route unconditionally, not only where the operator remembered to offer one.
     */
    protected function hasAdverseChangeItem(LegalDocument $document): bool
    {
        return FreeTermination::adverse($this->changeSetFor($document));
    }

    /**
     * Whether the notice owes the free-termination line, by the same rule the re-consent form uses
     * to offer the termination ({@see FreeTermination}).
     */
    protected function owesFreeTermination(LegalDocument $document): bool
    {
        return FreeTermination::owed($document, $this->changeSetFor($document));
    }

    /**
     * One entry of the list: a translated label for what happened, in bold, the subject, then the
     * detail.
     *
     * A third party gets its EDPB Opinion 22/2024 Rz. 22 facets appended in parentheses, because a
     * name on its own does not tell a reader whether their data left the country. Its name leads
     * them when the subject says something else, such as the role the party takes.
     *
     * The label and the punctuation between the fields are the package's; the fields are set in as
     * text.
     */
    private function renderItem(LegalChangeItem $item): HtmlString
    {
        $label = trans('legal-consent::changes.types.'.$item->type->value);
        $label = is_string($label) && ! str_contains($label, 'legal-consent::') ? $label : $item->type->value;

        $line = '- **'.$label.':** :subject';
        $typed = ['subject' => $item->subject];

        if (($item->detail ?? '') !== '') {
            $line .= ' — :detail';
            $typed['detail'] = (string) $item->detail;
        }

        // array_values() changes nothing observable: implode() and the emptiness check below read
        // no keys.
        $facets = array_values(array_filter([
            $item->party_name !== $item->subject ? $item->party_name : null,
            $item->party_location,
            $item->purpose,
            $item->party_contact,
        ], static fn (?string $value): bool => $value !== null && $value !== ''));

        if ($item->type->namesAParty() && $facets !== []) {
            $names = [];

            foreach ($facets as $index => $facet) {
                $names[] = ':facet'.$index;
                $typed['facet'.$index] = (string) $facet;
            }

            $line .= ' ('.implode(', ', $names).')';
        }

        return MarkdownText::interpolate($line, $typed);
    }

    /**
     * Loaded once per instance. A queued notice renders twice for the same recipient — once for the
     * mail and once for the append-only proof row that records what was delivered — so an
     * unmemoized lookup would double the change-set query on every notice the sweep sends.
     */
    private function changeSetFor(LegalDocument $document): ?LegalChangeSet
    {
        if ($this->changeSetLoaded) {
            return $this->changeSet;
        }

        $this->changeSetLoaded = true;

        $id = $document->getKey();
        $this->changeSet = is_int($id) ? app(ChangeItemsAuthor::class)->published($id) : null;

        return $this->changeSet;
    }
}
