<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pushery\LegalConsent\Enums\ChangeItemType;
use Pushery\LegalConsent\Enums\ChangeSetState;
use Pushery\LegalConsent\Exceptions\InvalidChangeDescription;
use Pushery\LegalConsent\Models\LegalChangeItem;
use Pushery\LegalConsent\Models\LegalChangeSet;

/**
 * Writes the description of a pending change, one locale at a time.
 *
 * Everything here is PLAIN TEXT, and that is a decision rather than an omission. A draft body is
 * deliberately sanitized HTML because it is a legal text rendered as a document; a change item is
 * rendered into a mail line and into `{{ }}`-escaped Blade, so an angle bracket in it reaches the
 * reader as the character it is. It is therefore kept as written. Stripping tags took the `<` of
 * "sinkt auf <100 EUR" for the start of one and dropped the rest of the sentence from a notice the
 * law requires, silently and without leaving the line empty.
 */
final class PendingChangeItems
{
    /**
     * What a text column holds on MySQL, in bytes: `headline` and `impact` of the description,
     * `detail` and `purpose` of an entry. PostgreSQL and SQLite put no limit on these columns.
     */
    public const int TEXT_MAX_BYTES = 65535;

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
     * The width of the entry columns `subject`, `party_name`, `party_location` and `party_contact`,
     * in characters, or null when they have none.
     *
     * Migration 000017 declares them without a width, so they take the default string length of the
     * schema builder: 255, unless the application sets another with `Schema::defaultStringLength()`.
     * Without a default length, PostgreSQL creates them unbounded.
     */
    public static function shortFieldMaxLength(): ?int
    {
        return Builder::$defaultStringLength ?: null;
    }

    /**
     * The characteristics of the change — § 327r Abs. 2 Satz 2 Nr. 1 BGB.
     */
    public function headline(string $headline): self
    {
        $this->headline = $this->plain($headline);
        $this->assertFitsText('headline', $this->headline);

        return $this;
    }

    /**
     * What it is likely to mean for the reader — WP260 rev.01 Rz. 31, a separate obligation from
     * naming the change itself.
     */
    public function impact(string $impact): self
    {
        $this->impact = $this->plain($impact);
        $this->assertFitsText('impact', $this->impact);

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

        // One transaction for the whole replacement. The header is rewritten, the entries removed and
        // the new ones written one statement each, so an entry the database refuses would otherwise
        // leave the new header beside the entries written before it and none of the earlier ones: a
        // draft the next release freezes as it stands.
        return DB::transaction(function () use ($tenantId): LegalChangeSet {
            // Located, then force-filled — never firstOrNew() with the identity as attributes. This
            // model guards every column on purpose (a frozen row is the record of what a subject was
            // told), so the convenience method's mass assignment is exactly what it is there to refuse.
            $set = LegalChangeSet::model()::query()
                ->where('key', $this->key)
                ->where('locale', $this->locale)
                ->where('tenant_id', $tenantId)
                ->where('version', LegalChangeSet::DRAFT_VERSION)
                ->first() ?? LegalChangeSet::resolve();

            $set->forceFill([
                'key' => $this->key,
                'locale' => $this->locale,
                // Also unobservable, for a second reason: the model uses `BelongsToTenant`,
                // which stamps the tenant on create. With tenancy off the column's '' default agrees
                // as well, so its absence is unobservable in both configurations. Kept because the
                // lookup above filters on this column and the write should say what it writes.
                'tenant_id' => $tenantId,
                // This line stores what the schema would store anyway: `version` defaults to '' in
                // migration 000016, and DRAFT_VERSION is ''. It stays because the row is looked up
                // by this sentinel above, and a default that agrees with a constant by coincidence
                // is worth writing down rather than relying on.
                'version' => LegalChangeSet::DRAFT_VERSION,
                'state' => ChangeSetState::Draft,
                'headline' => $this->headline,
                'impact' => $this->impact,
            ])->save();

            $set->items()->delete();

            foreach ($this->items as $position => $item) {
                LegalChangeItem::model()::query()->forceCreate([
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
        });
    }

    private function item(ChangeItemType $type, string $subject, ?string $detail, ?string $partyName = null, ?string $partyLocation = null, ?string $partyContact = null, ?string $purpose = null): self
    {
        $item = [
            'type' => $type,
            'subject' => $this->plain($subject),
            'detail' => $this->plainOrNull($detail),
            'party_name' => $this->plainOrNull($partyName),
            'party_location' => $this->plainOrNull($partyLocation),
            'party_contact' => $this->plainOrNull($partyContact),
            'purpose' => $this->plainOrNull($purpose),
        ];

        foreach (['subject' => $item['subject'], 'partyName' => $item['party_name'], 'partyLocation' => $item['party_location'], 'partyContact' => $item['party_contact']] as $argument => $value) {
            $this->assertFitsShortColumn($argument, $value);
        }

        $this->assertFitsText('detail', $item['detail']);
        $this->assertFitsText('purpose', $item['purpose']);

        $this->items[] = $item;

        return $this;
    }

    /**
     * A value is measured as it is stored, after the whitespace is collapsed, and in characters, as
     * PostgreSQL and MySQL count a string column. SQLite keeps a longer value and the other two
     * refuse the save, so the refusal comes here, naming the argument, before anything is written.
     */
    private function assertFitsShortColumn(string $argument, ?string $value): void
    {
        $maxLength = self::shortFieldMaxLength();

        if ($maxLength !== null && $value !== null && mb_strlen($value) > $maxLength) {
            throw InvalidChangeDescription::tooLong($argument, mb_strlen($value), $maxLength);
        }
    }

    /**
     * Measured in bytes, as MySQL counts a text column; PostgreSQL and SQLite would keep the value.
     */
    private function assertFitsText(string $argument, ?string $value): void
    {
        if ($value !== null && strlen($value) > self::TEXT_MAX_BYTES) {
            throw InvalidChangeDescription::tooLarge($argument, strlen($value));
        }
    }

    /**
     * Collapse whitespace, because a value pasted out of a document arrives carrying line breaks
     * that would split a mail line in the middle of a sentence. Nothing else is taken out: see the
     * class docblock for why an angle bracket stays.
     */
    private function plain(string $value): string
    {
        // `Str::squish`, NOT a hand-rolled `\s+` collapse — the difference is a class of
        // character `\s` does not cover. Measured: a zero-width space survives the hand-rolled
        // form and leaves a line that is non-empty and INVISIBLE; `Str::squish` also strips it,
        // along with the soft hyphen and the byte-order mark (`Str::INVISIBLE_CHARACTERS`).
        //
        // It matters here more than in ordinary prose: this text goes into a change notice whose
        // body is HASHED into an append-only proof row. A line nobody can see, certified as what
        // was communicated, is the failure this package exists to prevent.
        return Str::squish($value);
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
