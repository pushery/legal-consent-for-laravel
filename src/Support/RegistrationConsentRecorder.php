<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * The single place that turns a registration form's `legal_*` fields into ledger
 * entries — used by both Way A (the Fortify trait) and Way B (the Registered listener),
 * so the two never double-write. A mandatory document is always recorded; a real consent
 * is recorded only when its checkbox was actually ticked.
 *
 * A mandatory document (contract/notice) is validation-required regardless of locale, so
 * if it has no active version in the recording locale we fall back to the default-locale
 * version rather than silently dropping the proof (which would leave a court-proof gap
 * behind a ticked box). Only when the key is entirely unpublished — or is an optional
 * consent — is it skipped (there is no version to prove acceptance of).
 */
final readonly class RegistrationConsentRecorder
{
    /**
     * @param  array<string, array<string, mixed>>  $documents
     */
    public function __construct(
        private ConsentManager $consent,
        private array $documents,
        private string $defaultLocale = 'de',
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function record(Model $subject, array $input, ConsentContext $context, ?string $locale = null): void
    {
        $locale ??= $context->locale ?? $this->defaultLocale;

        $active = $this->activeByKey($locale);
        $fallback = $locale === $this->defaultLocale ? $active : $this->activeByKey($this->defaultLocale);

        foreach (array_keys($this->documents) as $key) {
            $document = $active->get($key);

            if (! $document instanceof LegalDocument) {
                // Not published in the recording locale. A MANDATORY document (contract/
                // notice) was validation-required, so fall back to the default-locale version
                // rather than drop its proof; an optional consent has nothing to record.
                // Mandatory-ness comes from the fallback version's own type (the DB is the
                // source of truth, not the config registry).
                $candidate = $fallback->get($key);

                if ($candidate instanceof LegalDocument && $candidate->type->isMandatory()) {
                    $document = $candidate;
                }
            }

            if (! $document instanceof LegalDocument) {
                continue; // optional consent, or the key is entirely unpublished
            }

            if ($document->type->requiresExplicitOptin() && ! $this->wasGiven($input["legal_{$key}"] ?? null)) {
                continue; // an optional consent that was not ticked
            }

            // Snapshot the version the recorder actually resolved (its own locale), which
            // may be the default-locale fallback rather than the requested locale.
            $this->consent->accept($subject, (string) $key, $context, $document->locale);
        }
    }

    /**
     * @return Collection<string, LegalDocument>
     */
    private function activeByKey(string $locale): Collection
    {
        return LegalDocument::query()
            ->select(['key', 'type', 'locale'])
            ->where('locale', $locale)
            ->where('is_active', true)
            ->get()
            ->keyBy('key');
    }

    private function wasGiven(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
