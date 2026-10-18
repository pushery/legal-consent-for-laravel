<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Exceptions\DocumentChangedException;
use Pushery\LegalConsent\Exceptions\UnevidencedConsentException;
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
 * version rather than silently dropping the proof (which would leave a ticked box with
 * nothing to show for it). Only when the key is entirely unpublished — or is an optional
 * consent — is it skipped (there is no version to prove acceptance of).
 */
final readonly class RegistrationConsentRecorder
{
    /** Record the row and log a warning — the default, and the only value that breaks nobody. */
    public const string WITHOUT_FORM_FIELDS_WARN = 'warn';

    /** Refuse the whole registration instead of recording an unevidenced mandatory consent. */
    public const string WITHOUT_FORM_FIELDS_REFUSE = 'refuse';

    /**
     * @param  array<string, array<string, mixed>>  $documents
     * @param  string  $withoutFormFields  what to do when a mandatory document is about to be
     *                                     recorded from a request that carries no `legal_<key>`
     *                                     field. Anything other than `refuse` means `warn`: an
     *                                     unrecognized value must never be the thing that starts
     *                                     failing registrations, and `legal-consent:doctor` names
     *                                     it instead.
     */
    public function __construct(
        private ConsentManager $consent,
        private array $documents,
        private string $defaultLocale = 'de',
        private string $withoutFormFields = self::WITHOUT_FORM_FIELDS_WARN,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<array-key, mixed>  $shownWordings  the sentence the form showed next to each
     *                                                  document in this request, keyed by document
     *                                                  key. A form that builds its sentence per
     *                                                  request (naming only the texts published in the
     *                                                  reader's language, for instance) passes it here,
     *                                                  and the proof freezes that sentence instead of
     *                                                  a configured one. A key it leaves out, or a
     *                                                  value that is not a non-blank string, keeps the
     *                                                  configured sentence.
     */
    public function record(Model $subject, array $input, ConsentContext $context, ?string $locale = null, array $shownWordings = []): void
    {
        // Nobody is signed in while an account registers, so a resolver that reads the signed-in
        // user places the documents and the rows in the shared '' bucket, where the account's own
        // tenant never looks. When the request names no tenant, the subject's own one is pinned
        // around the read and the writes, as the notice sweeps pin the version's.
        $tenants = app(TenantContext::class);
        $own = $tenants->current() === '' ? $tenants->tenantOf($subject) : '';

        if ($own !== '') {
            $tenants->forTenant($own, function () use ($subject, $input, $context, $locale, $shownWordings): void {
                $this->write($subject, $input, $context, $locale, $shownWordings);
            });

            return;
        }

        $this->write($subject, $input, $context, $locale, $shownWordings);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<array-key, mixed>  $shownWordings
     */
    private function write(Model $subject, array $input, ConsentContext $context, ?string $locale, array $shownWordings): void
    {
        // The locale the subject actually saw, when a caller passes it (Way A's argument, or the
        // context). Falling straight to default_locale — as this did — is how a validated checkbox
        // could record nothing at all: an English app that keeps the shipped `default_locale => 'de'`
        // and publishes in `en` only had its rules resolve `en` (app locale) while the recorder
        // looked in `de` and found nothing. Validation said yes, the ledger stayed empty.
        $locale ??= $context->locale ?? app()->getLocale();

        // Resolution chain: what was seen, then the configured fallback, then the default. Each step
        // is tried in order and the first hit wins, so the recorder can only ever freeze a version
        // that exists — and it records which locale it actually used (the ledger row carries it).
        // The same chain the rules and the checklist walk, so the three resolve identically.
        $chain = RegistrationLocaleChain::resolve($locale, $this->defaultLocale);
        $resolved = [];

        foreach ($chain as $candidate) {
            $resolved[$candidate] = $this->activeByKey($candidate);
        }

        $active = $resolved[$locale] ?? $this->activeByKey($locale);

        // Mandatory keys about to be recorded without the form field that would have carried the
        // subject's tick. Collected across the loop so one warning names all of them rather than
        // one line per key — and, under `refuse`, so the decision is made once with the whole set
        // known rather than aborting on whichever key happened to come first.
        $unevidenced = [];

        // Pages flagged for registration whose control this request does not carry, so nothing was
        // shown to acknowledge. Collected for one warning, for the reason given above.
        $unshown = [];

        // Mandatory documents with no published version behind a ticked control they share with a
        // document that does resolve, left open rather than refused. Collected for one warning, for
        // the same reason.
        $open = [];

        // Resolution is separated from writing, and the separation is the whole reason `refuse`
        // can be honest: it decides before the first accept(), so a registration records all of
        // its consents or none. Refusing mid-loop would leave a partial ledger in the one table
        // that cannot be corrected afterwards. The same holds for a form that showed a version
        // which is no longer the one to record: that refusal is decided here too, before anything
        // is written, rather than by the manager in the middle of the writes.
        $pending = [];

        foreach (array_keys($this->documents) as $key) {
            $document = $active->get($key);

            if (! $document instanceof LegalDocument) {
                // Not published in the locale the subject saw. A mandatory document was
                // validation-required, so walk the rest of the chain rather than drop its proof.
                $document = $this->mandatoryFallback((string) $key, $resolved, $chain);

                if ($document instanceof LegalDocument) {
                    // A fallback means the subject agreed to a text in a language they may not
                    // have been shown. The row itself records which locale was frozen, so the
                    // proof stays self-describing — but this is worth surfacing, not swallowing.
                    Log::warning('legal-consent: recorded a mandatory consent from a fallback locale', [
                        'document_key' => $key,
                        'seen_locale' => $locale,
                        'recorded_locale' => $document->locale,
                        'subject_type' => (string) $subject->getMorphClass(),
                    ]);
                }
            }

            if (! $document instanceof LegalDocument) {
                // A mandatory document that resolves in no locale of the chain, behind a box that came
                // back ticked, has no version to freeze. When no other document behind that box
                // resolves, the tick was for this one alone, and a ticked box with an empty ledger is
                // a consent the application believes it holds and cannot prove (Art. 7(1)), so the
                // registration is refused.
                //
                // A box several documents share is ticked for the documents the form showed under
                // it. While one of them resolves here, that one accounts for the tick: the rules
                // asked nothing for a document with no published version, so this one was not part
                // of the answer. It stays open until a version is published, and the warning after
                // the writes names it.
                $field = RegistrationField::forDocument((string) $key);

                if ($this->isMandatoryByConfig((string) $key) && $this->wasGiven($input[$field] ?? null)) {
                    if (! $this->fieldCoversAResolvedDocument($field, $active, $resolved, $chain)) {
                        throw UnrecordableConsentException::for((string) $key, $chain);
                    }

                    $open[] = (string) $key;
                }

                continue; // optional consent, the key is entirely unpublished, or it stays open
            }

            // An informational page binds nobody, so by default there is nothing to freeze: writing
            // a row would claim the subject acknowledged an Impressum they were never shown a
            // control for, and it never reaches validation either, so a tick cannot exist to honor.
            //
            // Unless the operator says their form does show it. Then the opposite is true: the box
            // was ticked over four pages and a ledger naming one of them is the misleading record.
            // The flag says which pages the form can show; whether this request showed one, and
            // whether the box next to it was ticked, is asked below, because no validation rule
            // asks it: RegistrationRules gives a page that binds nobody no rule at all.
            //
            // A page flagged for registration with no configured sentence is recorded too when the
            // form handed over the one it showed: then the request carries the only sentence that
            // could be true, and the flag says the form showed the page.
            $shown = $this->shownWording($shownWordings, (string) $key);

            if (! RegistrationAcknowledgment::isRecordedAtRegistration($document)
                && ($shown === null || ! RegistrationAcknowledgment::isFlaggedForRegistration((string) $key))) {
                continue;
            }

            // A page that binds nobody is written only where its control was rendered and ticked:
            // its own field, or the one the registry names for the box it shares with other pages.
            // Without the control this request showed nothing to acknowledge, and an empty box is
            // the person saying no. Either way the ledger would hold an acknowledgment nobody gave.
            if (! $document->type->isConsentBearing()) {
                $field = RegistrationField::forDocument((string) $key);

                if (! array_key_exists($field, $input)) {
                    $unshown[] = (string) $key;

                    continue;
                }

                if (! $this->wasGiven($input[$field])) {
                    continue;
                }
            }

            if ($document->type->requiresExplicitOptin() && ! $this->wasGiven($input[RegistrationField::forDocument((string) $key)] ?? null)) {
                continue; // an optional consent that was not ticked
            }

            // Opt-in accept-time guard: if the form rendered the `legal_{key}_hash` hidden field
            // (the checklist item's render-time fingerprint), pass it so a version released between
            // page load and submit is caught (DocumentChangedException) instead of silently frozen —
            // the registration path's own TOCTOU, whose window is minutes. A missing field is null,
            // which `accept()` treats as "no check requested", so a form that does not render it keeps
            // the prior behavior exactly.
            $expectedHash = $input[RegistrationField::hashForDocument((string) $key)] ?? null;
            $this->assertShownVersionIsCurrent((string) $key, $document, is_string($expectedHash) ? $expectedHash : null);

            // A mandatory document is accepted unconditionally, because RegistrationRules made its
            // box `required` and validation already ran. That reasoning holds only while a form ran
            // — and Way B fires on the standard `Registered` event, which an external identity
            // provider raises with no form at all. Then nothing validated anything, and the row whose
            // whole purpose is to prove a human acted gets written without one having.
            //
            // The absence of that field in the input is not an inference about the application —
            // it is this request, observed. (Asking the route table whether a registration form
            // exists cannot be made reliable: an application may name that route anything.)
            //
            // Which field is asked for is the registry's answer rather than a convention this site
            // derives: a form with one control for four pages declares `registration_field` once
            // per document, and this check looks for the control that was actually rendered.
            //
            // Whether that is reported or refused is the operator's call, and the default is to
            // report: the check can only look for the field name RegistrationRules generates, so an
            // application with its own form, naming its fields differently, validates the tick
            // perfectly well and still sends no `legal_terms`. Refusing by default would turn its
            // registrations into failures on the one path every current consumer uses. Where the
            // flag earns its keep is the case it was written for — an external identity provider
            // raising Registered with no form behind it at all.
            if ($document->type->isMandatory() && ! array_key_exists(RegistrationField::forDocument((string) $key), $input)) {
                $unevidenced[] = (string) $key;
            }

            // Snapshot the version the recorder actually resolved (its own locale), which
            // may be the default-locale fallback rather than the requested locale.
            //
            // Both ride along because the document is in hand here; resolving either again in the
            // loop below could resolve it differently.
            //
            // The door is decided by the type, not by the presence of a sentence, and that used to
            // be the same thing: a consent-bearing document had its wording nulled out precisely so
            // the loop would route it to accept(). That made the sentence unreachable for the one
            // document where it matters most — a binding text folded into a line that names four,
            // whose proof then froze words the subject never read.
            //
            // The sentence this request showed wins over the configured one: a form that names the
            // texts published in the reader's language shows a different line per language, and a
            // configured line would be the wrong proof in every language but one.
            $pending[] = [
                (string) $key,
                $document->locale,
                is_string($expectedHash) ? $expectedHash : null,
                $shown ?? RegistrationAcknowledgment::wordingFor((string) $key),
                $document->type->isConsentBearing(),
            ];
        }

        if ($unevidenced !== [] && $this->withoutFormFields === self::WITHOUT_FORM_FIELDS_REFUSE) {
            throw UnevidencedConsentException::for($unevidenced);
        }

        foreach ($pending as [$key, $documentLocale, $expectedHash, $registrationWording, $bindsSomebody]) {
            // An informational page the operator flagged goes through acknowledge(), which is a
            // different act and not a stricter accept(): it records that the subject was shown the
            // page, under the sentence the operator's own form put next to it, and it binds nobody.
            // `accept()` would refuse it — rightly, because the sentence it guards is one this
            // document does not have.
            if (! $bindsSomebody) {
                // Narrowing a type, not inventing a value: a non-binding page gets this far only
                // with a sentence, the request's or the one `coversRegistration()` found.
                $this->consent->acknowledge($subject, $key, $context, (string) $registrationWording, $documentLocale, $expectedHash);

                continue;
            }

            // Null where the operator configured no registration line, which is every registry that
            // has not asked for one — and then the proof freezes this document's own published
            // wording, exactly as before.
            $this->consent->accept($subject, $key, $context, $documentLocale, $expectedHash, $registrationWording);
        }

        if ($open !== []) {
            Log::warning('legal-consent: recorded no consent for a mandatory document with no published version behind a shared registration field', [
                'document_keys' => $open,
                'fields' => array_map(RegistrationField::forDocument(...), $open),
                'locales' => $chain,
                'subject_type' => (string) $subject->getMorphClass(),
                'hint' => 'A tick on a shared control answers for the documents published behind it, so a '
                    .'document without a version stays open. Publish it (legal-consent:publish): the subject '
                    .'then owes its first acceptance, which legal-consent.gate.first_use asks for.',
            ]);
        }

        if ($unshown !== []) {
            Log::warning('legal-consent: acknowledged no page flagged for registration whose field the request does not carry', [
                'document_keys' => $unshown,
                'fields' => array_map(RegistrationField::forDocument(...), $unshown),
                'subject_type' => (string) $subject->getMorphClass(),
            ]);
        }

        if ($unevidenced !== []) {
            Log::warning('legal-consent: recorded a mandatory consent with no registration-form field present', [
                'document_keys' => $unevidenced,
                'subject_type' => (string) $subject->getMorphClass(),
                'method' => $context->method->value,
                'hint' => 'If your form shows ONE control for several documents, declare '
                    .'registration_field per document in the registry and this check looks for the '
                    .'control you actually rendered. Otherwise: Way B fires on Registered, which a '
                    .'sign-in through an external provider raises with no form. Record the first '
                    .'acceptance at an interstitial with ConsentMethod::FirstUseGate instead, turn '
                    .'off legal-consent.registration.listen_to_registered_event, or set '
                    .'legal-consent.registration.without_form_fields to \'refuse\' to make this a '
                    .'failed registration rather than a warning.',
            ]);
        }
    }

    /**
     * The sentence the form showed next to this document in this request, or null when the caller
     * passed none.
     *
     * Strictly a non-blank string: anything else is a caller who meant to pass something and did
     * not, and the configured sentence is what they had before they tried. Trimmed, like the
     * configured one, so the two cannot differ by whitespace alone.
     *
     * @param  array<array-key, mixed>  $shownWordings
     */
    private function shownWording(array $shownWordings, string $key): ?string
    {
        $shown = $shownWordings[$key] ?? null;

        return is_string($shown) && trim($shown) !== '' ? trim($shown) : null;
    }

    /**
     * The active documents of one locale, by key, with what the loop reads: the type, and the two
     * values the shown version is compared with before the first write. Without `content_hash` and
     * `ui_wording` that comparison read nulls and could not build the acceptance fingerprint.
     *
     * @return Collection<string, LegalDocument>
     */
    private function activeByKey(string $locale): Collection
    {
        return LegalDocument::model()::query()
            ->select(['key', 'type', 'locale', 'content_hash', 'ui_wording'])
            ->where('locale', $locale)
            ->where('is_active', true)
            ->get()
            ->keyBy('key');
    }

    // `mixed`: this is raw registration-form input ("1"/"true"/"on"/bool/null) taken before any
    // coercion, so the boolean interpretation is deferred to filter_var here rather than assumed.
    /**
     * Refuses, before the first row is written, a document whose form showed a version that is no
     * longer the one this registration would record.
     *
     * The manager asks the same question for each document as it writes it, and asked there alone
     * it refuses after the documents before it are already in the ledger: a registration that
     * throws and still holds part of its consents. The answer is the manager's own, the acceptance
     * fingerprint or the bare content hash for a binding text and the content hash for a page that
     * binds nobody, so the two places cannot disagree about what counts as changed.
     */
    private function assertShownVersionIsCurrent(string $key, LegalDocument $document, ?string $expectedHash): void
    {
        if ($expectedHash === null) {
            return;
        }

        $current = $document->type->isConsentBearing()
            ? [DefaultConsentManager::acceptanceFingerprint($document), $document->content_hash]
            : [$document->content_hash];

        if (! in_array($expectedHash, $current, true)) {
            throw DocumentChangedException::for($key, $expectedHash, $current[0]);
        }
    }

    private function wasGiven(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether the configured registry declares this key mandatory. Used only to decide whether an
     * unresolvable key is an error: with no published row anywhere there is no type to read, so the
     * config is the only thing left that knows the key was supposed to be required.
     */
    private function isMandatoryByConfig(string $key): bool
    {
        // No explicit legal_basis means the registry never claimed this key was mandatory, so an
        // unresolvable one is simply not offered — not an error. Only a key the config declares
        // mandatory can be an unrecordable-consent failure; anything looser would turn a merely
        // unpublished document into a broken registration.
        $basis = $this->documents[$key]['legal_basis'] ?? null;

        if (! is_string($basis) || $basis === '') {
            return false;
        }

        return DocumentType::fromLegalBasis($basis)->isMandatory();
    }

    /**
     * The version of a mandatory document the rest of the chain resolves, for a key with nothing
     * published in the locale the subject saw.
     *
     * Only a mandatory document falls back: it is validation-required whatever the language, so its
     * proof is not dropped for want of a translation. An optional consent has nothing to fall back to
     * (Art. 7(4): it may never be required, so offering a language nobody asked for is no
     * improvement). Mandatory-ness comes from the resolved row's own type, because the database is the
     * source of truth, not the config registry.
     *
     * @param  array<string, Collection<string, LegalDocument>>  $resolved
     * @param  list<string>  $chain
     */
    private function mandatoryFallback(string $key, array $resolved, array $chain): ?LegalDocument
    {
        foreach ($chain as $candidate) {
            $row = $resolved[$candidate]->get($key);

            if ($row instanceof LegalDocument && $row->type->isMandatory()) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether a document this registration resolves stands behind the field.
     *
     * A field that several documents name is one control, and a tick on it answers for the documents
     * the form could show there. A document resolves as it does in the recording loop: published in
     * the locale the subject saw, or, for a mandatory one, through the rest of the chain. The document
     * asking resolves nowhere, which is why it asks, so it never answers for itself.
     *
     * @param  Collection<string, LegalDocument>  $active
     * @param  array<string, Collection<string, LegalDocument>>  $resolved
     * @param  list<string>  $chain
     */
    private function fieldCoversAResolvedDocument(string $field, Collection $active, array $resolved, array $chain): bool
    {
        foreach (array_keys($this->documents) as $key) {
            if (RegistrationField::forDocument((string) $key) !== $field) {
                continue;
            }

            if ($active->get($key) instanceof LegalDocument || $this->mandatoryFallback((string) $key, $resolved, $chain) instanceof LegalDocument) {
                return true;
            }
        }

        return false;
    }
}
