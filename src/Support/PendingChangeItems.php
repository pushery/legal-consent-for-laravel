<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Enums\ChangeItemType;
use Pushery\LegalConsent\Enums\ChangeSetState;
use Pushery\LegalConsent\Models\LegalChangeItem;
use Pushery\LegalConsent\Models\LegalChangeSet;

/**
 * Writes the description of a pending change, one locale at a time.
 *
 * Everything here is PLAIN TEXT, and that is a decision rather than an omission. A draft body is
 * deliberately sanitized HTML because it is a legal text rendered as a document; a change item is
 * rendered into a mail line and into `{{ }}`-escaped Blade, so markup in it cannot be displayed —
 * it can only be shown to the reader as literal angle brackets. Running it through
 * `LegalHtmlSanitizer` would produce markup that survives the sanitizer and then leaks as visible
 * source, so it is stripped instead.
 */
final class PendingChangeItems
{
    /** @var list<array{type: ChangeItemType, subject: string, detail: ?string, party_name: ?string, party_location: ?string, party_contact: ?string, purpose: ?string}> */
    private array $items = [];

    private ?string $headline = null;

    private ?string $impact = null;

    public function __construct(
        private readonly string $key,
        private readonly string $locale,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * The characteristics of the change — § 327r Abs. 2 Satz 2 Nr. 1 BGB.
     */
    public function headline(string $headline): self
    {
        $this->headline = $this->plain($headline);

        return $this;
    }

    /**
     * What it is likely to mean for the reader — WP260 rev.01 Rz. 31, a separate obligation from
     * naming the change itself.
     */
    public function impact(string $impact): self
    {
        $this->impact = $this->plain($impact);

        return $this;
    }

    public function added(string $subject, ?string $detail = null, ?string $partyName = null, ?string $partyLocation = null, ?string $partyContact = null, ?string $purpose = null): self
    {
        return $this->item(ChangeItemType::Added, $subject, $detail, $partyName, $partyLocation, $partyContact, $purpose);
    }

    public function removed(string $subject, ?string $detail = null, ?string $partyName = null, ?string $partyLocation = null, ?string $partyContact = null, ?string $purpose = null): self
    {
        return $this->item(ChangeItemType::Removed, $subject, $detail, $partyName, $partyLocation, $partyContact, $purpose);
    }

    public function modified(string $subject, ?string $detail = null): self
    {
        return $this->item(ChangeItemType::Modified, $subject, $detail);
    }

    public function clarified(string $subject, ?string $detail = null): self
    {
        return $this->item(ChangeItemType::Clarified, $subject, $detail);
    }

    /**
     * Broader reach — the "optional becomes required" case, which reads as an addition and is a
     * narrowing of the reader's choice.
     */
    public function extended(string $subject, ?string $detail = null, ?string $partyName = null, ?string $partyLocation = null, ?string $partyContact = null, ?string $purpose = null): self
    {
        return $this->item(ChangeItemType::Extended, $subject, $detail, $partyName, $partyLocation, $partyContact, $purpose);
    }

    public function restricted(string $subject, ?string $detail = null): self
    {
        return $this->item(ChangeItemType::Restricted, $subject, $detail);
    }

    /**
     * Persist as THE draft for this key + locale + tenant, replacing whatever was there.
     *
     * Replace rather than append: an operator who runs the authoring twice means the second version,
     * and an authoring API that silently accumulated would produce a notice listing every draft the
     * operator ever tried.
     */
    public function save(): LegalChangeSet
    {
        $tenantId = $this->tenant->enabled() ? $this->tenant->current() : '';

        // Located, then force-filled — never firstOrNew() with the identity as attributes. This
        // model guards every column on purpose (a frozen row is the record of what a subject was
        // told), so the convenience method's mass assignment is exactly what it is there to refuse.
        $set = LegalChangeSet::query()
            ->where('key', $this->key)
            ->where('locale', $this->locale)
            ->where('tenant_id', $tenantId)
            ->where('version', LegalChangeSet::DRAFT_VERSION)
            ->first() ?? new LegalChangeSet;

        $set->forceFill([
            'key' => $this->key,
            'locale' => $this->locale,
            'tenant_id' => $tenantId,
            'version' => LegalChangeSet::DRAFT_VERSION,
            'state' => ChangeSetState::Draft,
            'headline' => $this->headline,
            'impact' => $this->impact,
        ])->save();

        $set->items()->delete();

        foreach ($this->items as $position => $item) {
            LegalChangeItem::query()->forceCreate([
                'change_set_id' => $set->getKey(),
                'state' => ChangeSetState::Draft,
                'position' => $position,
                'type' => $item['type'],
                'subject' => $item['subject'],
                'detail' => $item['detail'],
                'party_name' => $item['party_name'],
                'party_location' => $item['party_location'],
                'party_contact' => $item['party_contact'],
                'purpose' => $item['purpose'],
            ]);
        }

        return $set->refresh();
    }

    private function item(ChangeItemType $type, string $subject, ?string $detail, ?string $partyName = null, ?string $partyLocation = null, ?string $partyContact = null, ?string $purpose = null): self
    {
        $this->items[] = [
            'type' => $type,
            'subject' => $this->plain($subject),
            'detail' => $this->plainOrNull($detail),
            'party_name' => $this->plainOrNull($partyName),
            'party_location' => $this->plainOrNull($partyLocation),
            'party_contact' => $this->plainOrNull($partyContact),
            'purpose' => $this->plainOrNull($purpose),
        ];

        return $this;
    }

    /**
     * Strip markup and collapse whitespace. Both halves matter: the first because this text is
     * rendered escaped, the second because a value pasted out of a document arrives carrying line
     * breaks that would split a mail line in the middle of a sentence.
     */
    private function plain(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
    }

    private function plainOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $plain = $this->plain($value);

        return $plain === '' ? null : $plain;
    }
}
