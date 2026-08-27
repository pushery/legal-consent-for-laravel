<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;
use Pushery\LegalConsent\Models\LegalChangeItem;
use Pushery\LegalConsent\Models\LegalChangeSet;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\ChangeItemsAuthor;

/**
 * Renders the operator's description of a change into a notice — what every real change notice
 * leads with and what a fixed per-type sentence can never say.
 *
 * As LINES on the MailMessage, never as a replacement view. That is the load-bearing choice: the
 * durable-medium proof row is built from `subject + introLines + actionText + outroLines`, so a
 * delta rendered into lines lands in `notice_body` and its sha256 automatically, while a delta
 * rendered by swapping the view would be visible to the subject and absent from the record of what
 * they were shown.
 *
 * Nothing here is required. A version with no description renders exactly the notice it rendered
 * before, byte for byte — the feature has to be worth turning on, not unavoidable.
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

        if (($set->headline ?? '') !== '') {
            $mail->line($set->headline);
        }

        foreach ($set->items as $item) {
            $mail->line($this->renderItem($item));
        }

        // Impact AFTER the list. § 327r Abs. 2 Satz 2 Nr. 1 BGB wants the characteristics of the
        // change and WP260 rev.01 Rz. 31 separately wants its likely effect — a reader who has just
        // seen what moved is the one who can use the second sentence.
        if (($set->impact ?? '') !== '') {
            $mail->line($set->impact);
        }

        return $mail;
    }

    /**
     * Does any entry make the change disadvantageous? A notice that takes something away owes the
     * exit route unconditionally, not only where the operator remembered to offer one.
     */
    protected function hasAdverseChangeItem(LegalDocument $document): bool
    {
        $set = $this->changeSetFor($document);

        if (! $set instanceof LegalChangeSet) {
            return false;
        }

        foreach ($set->items as $item) {
            if ($item->type->isAdverse()) {
                return true;
            }
        }

        return false;
    }

    /**
     * One line per entry: a translated label for what happened, the subject, then the detail.
     *
     * A third party gets its EDPB Opinion 22/2024 Rz. 22 facets appended in parentheses, because a
     * name on its own does not tell a reader whether their data left the country.
     */
    private function renderItem(LegalChangeItem $item): string
    {
        $label = trans('legal-consent::changes.types.'.$item->type->value);
        $label = is_string($label) && ! str_contains($label, 'legal-consent::') ? $label : $item->type->value;

        $line = "{$label}: {$item->subject}";

        if (($item->detail ?? '') !== '') {
            $line .= ' — '.$item->detail;
        }

        $facets = array_values(array_filter([
            $item->party_location,
            $item->purpose,
            $item->party_contact,
        ], static fn (?string $value): bool => $value !== null && $value !== ''));

        if ($item->type->namesAParty() && $facets !== []) {
            $line .= ' ('.implode(', ', $facets).')';
        }

        return $line;
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
