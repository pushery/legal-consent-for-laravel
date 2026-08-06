<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Pushery\LegalConsent\Enums\ChangeSetState;
use Pushery\LegalConsent\Models\LegalChangeItem;
use Pushery\LegalConsent\Models\LegalChangeSet;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Models\Scopes\TenantScope;

/**
 * Turns the working draft of a change description into the frozen record attached to a version.
 *
 * A TRANSITION, not a copy: the same row gains the version, the document, the content hash and the
 * frozen state. Copying would leave two rows that can disagree, and the whole value of the record
 * is that it says what the notice said.
 *
 * Called from inside the publisher's transaction, so a publish that rolls back leaves the draft
 * exactly where it was — an operator does not lose their description because a later invariant
 * refused the release.
 */
final class ChangeItemsFreezer
{
    /**
     * Freeze the draft for this document, if one exists.
     *
     * Absence is not an error here. Not every change owes a description — a silent editorial fix
     * does not, and a package that refused to publish without one would make the feature mandatory
     * for everyone the moment it shipped. Where a description IS required, the release pre-flight
     * says so before the transaction opens, which is the place that can still be helpful about it.
     */
    public function freeze(LegalDocument $document, ?string $sourceFingerprint = null): ?LegalChangeSet
    {
        // Coalesce the tenant to the shared bucket rather than reading the attribute directly. A row
        // that was just created carries whatever `forceCreate` was given, and with tenancy off that
        // is nothing at all — the empty string is the database default, so the in-memory model says
        // null while every stored row says ''. Matching on null finds nothing, silently, and the
        // publish then looks like it simply had no description to freeze.
        $tenantId = $document->tenant_id ?? '';

        $draft = LegalChangeSet::query()
            ->withoutGlobalScope(TenantScope::class) // the publish runs under the operator's session, the row under its own tenant
            ->where('key', $document->key)
            ->where('locale', $document->locale)
            ->where('tenant_id', $tenantId)
            ->where('version', LegalChangeSet::DRAFT_VERSION)
            ->first();

        if (! $draft instanceof LegalChangeSet) {
            return null;
        }

        $items = LegalChangeItem::query()
            ->where('change_set_id', $draft->getKey())
            ->orderBy('position')
            ->get();

        $draft->forceFill([
            'version' => $document->version,
            'state' => ChangeSetState::Published,
            'document_id' => $document->getKey(),
            'document_content_hash' => $document->content_hash,
            'source_fingerprint' => $sourceFingerprint ?? $draft->source_fingerprint,
            'change_hash' => $this->hash($draft, $items),
            'published_at' => CarbonImmutable::now(),
        ])->save();

        // The children carry the state too, so the database trigger can decide on one row. Written
        // with a bulk update rather than through the model, because the model hook would refuse the
        // very transition it is there to protect.
        LegalChangeItem::query()
            ->where('change_set_id', $draft->getKey())
            ->update(['state' => ChangeSetState::Published->value]);

        return $draft->refresh();
    }

    /**
     * A hash over the frozen content, in a form that does not depend on how the database returned
     * it: field order fixed here, items in their stored order.
     *
     * @param  Collection<int, LegalChangeItem>  $items
     */
    private function hash(LegalChangeSet $set, Collection $items): string
    {
        $parts = [$set->headline ?? '', $set->impact ?? ''];

        foreach ($items as $item) {
            $parts[] = implode('|', [
                $item->type->value,
                $item->subject,
                $item->detail ?? '',
                $item->party_name ?? '',
                $item->party_location ?? '',
                $item->party_contact ?? '',
                $item->purpose ?? '',
            ]);
        }

        return hash('sha256', implode("\n", $parts));
    }
}
