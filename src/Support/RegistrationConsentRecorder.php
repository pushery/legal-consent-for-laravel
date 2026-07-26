<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Exceptions\UnrecordableConsentException;
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
        // The locale the subject actually SAW, when a caller passes it (Way A's argument, or the
        // context). Falling straight to default_locale — as this did — is how a validated checkbox
        // could record nothing at all: an English app that keeps the shipped `default_locale => 'de'`
        // and publishes in `en` only had its rules resolve `en` (app locale) while the recorder
        // looked in `de` and found nothing. Validation said yes, the ledger stayed empty.
        $locale ??= $context->locale ?? app()->getLocale();

        // Resolution chain: what was seen, then the configured fallback, then the default. Each step
        // is tried in order and the FIRST hit wins, so the recorder can only ever freeze a version
        // that exists — and it records which locale it actually used (the ledger row carries it).
        // The SAME chain the rules and the checklist walk, so the three resolve identically.
        $chain = RegistrationLocaleChain::resolve($locale, $this->defaultLocale);
        $resolved = [];

        foreach ($chain as $candidate) {
            $resolved[$candidate] = $this->activeByKey($candidate);
        }

        $active = $resolved[$locale] ?? $this->activeByKey($locale);

        foreach (array_keys($this->documents) as $key) {
            $document = $active->get($key);

            if (! $document instanceof LegalDocument) {
                // Not published in the locale the subject saw. A MANDATORY document was
                // validation-required, so walk the rest of the chain rather than drop its proof;
                // an optional consent has nothing to fall back to (Art. 7(4): it may never be
                // required, so offering a language nobody asked for is no improvement).
                // Mandatory-ness comes from the resolved row's own type — the DB is the source of
                // truth, not the config registry.
                foreach ($chain as $candidate) {
                    $row = $resolved[$candidate]->get($key);

                    if ($row instanceof LegalDocument && $row->type->isMandatory()) {
                        $document = $row;

                        // A fallback means the subject agreed to a text in a language they may not
                        // have been shown. The row itself records WHICH locale was frozen, so the
                        // proof stays self-describing — but this is worth surfacing, not swallowing.
                        Log::warning('legal-consent: recorded a mandatory consent from a fallback locale', [
                            'document_key' => $key,
                            'seen_locale' => $locale,
                            'recorded_locale' => $row->locale,
                            'subject_type' => $subject->getMorphClass(),
                        ]);

                        break;
                    }
                }
            }

            if (! $document instanceof LegalDocument) {
                // A MANDATORY document that resolves in NO locale of the chain: validation demanded
                // the checkbox and there is no version to freeze. Silently skipping is exactly the
                // hole this fix exists to close — a ticked box with an empty ledger is a consent the
                // app believes it holds and cannot prove (Art. 7(1)). Fail loudly instead.
                if ($this->isMandatoryByConfig((string) $key) && $this->wasGiven($input["legal_{$key}"] ?? null)) {
                    throw UnrecordableConsentException::for((string) $key, $chain);
                }

                continue; // optional consent, or the key is entirely unpublished
            }

            // An informational page binds nobody, so there is nothing to freeze: writing a row
            // would claim the subject acknowledged an Impressum they were never shown a control
            // for. It never reaches validation either (RegistrationRules skips it), so a tick
            // cannot exist to honor.
            if (! $document->type->isConsentBearing()) {
                continue;
            }

            if ($document->type->requiresExplicitOptin() && ! $this->wasGiven($input["legal_{$key}"] ?? null)) {
                continue; // an optional consent that was not ticked
            }

            // OPT-IN accept-time guard: if the form rendered the `legal_{key}_hash` hidden field
            // (the checklist item's render-time fingerprint), pass it so a version released between
            // page load and submit is caught (DocumentChangedException) instead of silently frozen —
            // the registration path's own TOCTOU, whose window is minutes. A missing field is null,
            // which `accept()` treats as "no check requested", so a form that does not render it keeps
            // the prior behavior exactly.
            $expectedHash = $input["legal_{$key}_hash"] ?? null;

            // Snapshot the version the recorder actually resolved (its own locale), which
            // may be the default-locale fallback rather than the requested locale.
            $this->consent->accept($subject, (string) $key, $context, $document->locale, is_string($expectedHash) ? $expectedHash : null);
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

    // `mixed`: this is raw registration-form input ("1"/"true"/"on"/bool/null) taken before any
    // coercion, so the boolean interpretation is deferred to filter_var here rather than assumed.
    private function wasGiven(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether the configured registry declares this key mandatory. Used ONLY to decide whether an
     * unresolvable key is an error: with no published row anywhere there is no type to read, so the
     * config is the only thing left that knows the key was supposed to be required.
     */
    private function isMandatoryByConfig(string $key): bool
    {
        // No explicit legal_basis means the registry never claimed this key was mandatory, so an
        // unresolvable one is simply not offered — not an error. Only a key the config DECLARES
        // mandatory can be an unrecordable-consent failure; anything looser would turn a merely
        // unpublished document into a broken registration.
        $basis = $this->documents[$key]['legal_basis'] ?? null;

        if (! is_string($basis) || $basis === '') {
            return false;
        }

        return DocumentType::fromLegalBasis($basis)->isMandatory();
    }
}
