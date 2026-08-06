<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Enums\ChangeSetState;
use Pushery\LegalConsent\Models\LegalChangeSet;
use Pushery\LegalConsent\Models\Scopes\TenantScope;

/**
 * The entry point for describing a pending change, and the read side for everything that renders
 * or gates on one.
 *
 * Resolved from the container so a consuming app can bind its own; reached most easily through the
 * `ChangeItems` facade.
 */
final readonly class ChangeItemsAuthor
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * Start (or replace) the description of the pending change for one document and locale.
     */
    public function for(string $key, string $locale): PendingChangeItems
    {
        return new PendingChangeItems($key, $locale, $this->tenant);
    }

    /**
     * The editable draft, if one has been written.
     */
    public function draft(string $key, string $locale): ?LegalChangeSet
    {
        return LegalChangeSet::query()
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('version', LegalChangeSet::DRAFT_VERSION)
            ->with('items')
            ->first();
    }

    /**
     * The frozen description that belongs to a published version — what a notice renders.
     *
     * Matched on the DOCUMENT, not on (key, locale, version): a multi-tenant install can hold the
     * same key and version per tenant, and the document id is the only value that is unique across
     * all of them.
     */
    public function published(int $documentId): ?LegalChangeSet
    {
        return LegalChangeSet::query()
            ->withoutGlobalScope(TenantScope::class) // notices are rendered by a cross-tenant sweep
            ->where('document_id', $documentId)
            ->where('state', ChangeSetState::Published->value)
            ->with('items')
            ->first();
    }

    /**
     * Discard the working draft. Published sets are frozen and refuse deletion, so this can only
     * ever throw away work in progress.
     */
    public function discard(string $key, string $locale): bool
    {
        $draft = $this->draft($key, $locale);

        if (! $draft instanceof LegalChangeSet) {
            return false;
        }

        $draft->items()->delete();
        $draft->delete();

        return true;
    }

    /**
     * Which of these locales cannot be released yet, and why.
     *
     * The point is a release that would wrap a German notice around an English delta. The publisher
     * releases every locale as one change, so a description missing in one of them is not a smaller
     * problem than one missing everywhere — § 327r Abs. 1 Nr. 3 BGB, Art. 12(1) GDPR and DSA
     * Art. 14(1) all require the reader's own language.
     *
     * What it cannot see: a German field containing English text. That gap is real and is documented
     * rather than pretended away — the honest answer there is editorial process, not a predicate.
     *
     * @param  list<string>  $locales
     * @return array<string, string> locale => reason
     */
    public function blockingLocales(string $key, array $locales, ?string $currentFingerprint = null): array
    {
        $blocking = [];

        foreach ($locales as $locale) {
            $draft = $this->draft($key, $locale);

            if (! $draft instanceof LegalChangeSet) {
                $blocking[$locale] = 'no change description has been written';

                continue;
            }

            if (! $draft->isAuthored()) {
                $blocking[$locale] = 'the change description has no headline or no impact statement';

                continue;
            }

            // Staleness last, for the reason LegalDraftSet gives: a description that was never
            // finished is also "stale", and saying the source moved would misdescribe it.
            if ($currentFingerprint !== null
                && $draft->source_fingerprint !== null
                && $draft->source_fingerprint !== $currentFingerprint) {
                $blocking[$locale] = 'the legal text changed after this change description was written';
            }
        }

        return $blocking;
    }
}
