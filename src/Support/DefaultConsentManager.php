<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Content\PublishedDocument;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Events\ConsentConfirmationRequested;
use Pushery\LegalConsent\Events\ConsentObjected;
use Pushery\LegalConsent\Events\ConsentRecorded;
use Pushery\LegalConsent\Events\ConsentTerminated;
use Pushery\LegalConsent\Events\ConsentWithdrawn;
use Pushery\LegalConsent\Exceptions\DocumentChangedException;
use Pushery\LegalConsent\Exceptions\IncompatibleConsentActionException;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Exceptions\NotConfirmableException;
use Pushery\LegalConsent\Exceptions\NotConsentBearingException;
use Pushery\LegalConsent\Exceptions\NotGrantableException;
use Pushery\LegalConsent\Exceptions\NotObjectableException;
use Pushery\LegalConsent\Exceptions\NotTerminableException;
use Pushery\LegalConsent\Exceptions\NotWithdrawableException;
use Pushery\LegalConsent\Exceptions\SubjectKeyTooLong;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalDocument;
use RuntimeException;

/**
 * The default headless consent core. Every record/withdraw appends exactly one
 * immutable ledger entry, snapshotting the active version's proof fields plus the
 * server-side context, then fires the matching event.
 */
/**
 * Not `final`: the tamper-chain tail read is a `protected` seam ({@see latestChainedRow}) so a test
 * can force the stale read a concurrent writer sees and exercise the fork-retry deterministically.
 */
readonly class DefaultConsentManager implements ConsentManager
{
    /** How many times an append retries after a concurrent fork before surfacing the violation. */
    private const int MAX_CHAIN_ATTEMPTS = 5;

    /**
     * @param  array<string, array<string, mixed>>  $documents  the configured registration registry;
     *                                                          empty means "do not filter" (a bare
     *                                                          manager built in a test or by hand)
     */
    public function __construct(
        private ConsentGate $gate,
        private string $defaultLocale = 'de',
        private PublishedDocumentReader $reader = new PublishedDocumentReader,
        private array $documents = [],
        private bool $ageGateEnabled = false,
        private int $ageThreshold = 16,
        private DocumentUrlResolver $urls = new DocumentUrlResolver,
        /**
         * How long a double-opt-in request stays confirmable — a relative-time string Carbon can
         * parse ('7 days'), or null for no limit.
         *
         * Null is the default because a limit that nobody chose would start refusing confirmations
         * an application was already accepting. Where it is set it is the ledger-side half of what
         * a signed URL expiry does in the link: an application that does not sign its confirmation
         * links has no other check at all.
         */
        private ?string $confirmWithin = null,
    ) {}

    public function published(string $documentKey, ?string $locale = null): ?PublishedDocument
    {
        return $this->reader->read($documentKey, $locale ?? $this->defaultLocale);
    }

    public function registrationChecklist(?string $locale = null): array
    {
        $locale ??= $this->defaultLocale;

        $documents = $this->checklistRows($locale);

        // A MANDATORY document not published in the locale the visitor sees is still legally required,
        // so walk the rest of the resolution chain (fallback_locale, then default_locale) and SHOW it —
        // as its own-locale control, the one language they have to read before agreeing. This mirrors
        // RegistrationConsentRecorder and RegistrationRules, so the DISPLAYED, VALIDATED and RECORDED
        // sets resolve identically: the form can never require or record a document it did not show.
        // An optional consent has nothing to fall back to.
        foreach (RegistrationLocaleChain::resolve($locale, $this->defaultLocale) as $candidate) {
            if ($candidate === $locale) {
                continue; // already the base set
            }

            foreach ($this->checklistRows($candidate) as $key => $document) {
                if (! $documents->has($key) && $document->type->isMandatory()) {
                    $documents->put($key, $document);
                }
            }
        }

        // Intersect with the CONFIGURED registry, exactly as the rules and the recorder do. Without
        // this the checklist offered controls those two never validate or record — a published
        // document nobody registered would render a checkbox whose tick goes nowhere.
        if ($this->documents !== []) {
            // filter(), NOT only(): Eloquent\Collection::only() selects by PRIMARY KEY, not by the
            // array key — an override that silently returns an empty set here.
            $registered = $this->documents;
            $documents = $documents->filter(
                static fn (LegalDocument $document): bool => array_key_exists($document->key, $registered)
            );
        }

        // Order the checklist by document key in a package-defined, engine-independent way. SORT_STRING
        // (byte order) is explicit: the default SORT_REGULAR would compare numeric-looking keys
        // numerically, and the pre-cache v0.4 path ordered by the DB collation (locale/case-folding) —
        // both make the checkbox order depend on something outside the package. This pins it here.
        // An informational page (Impressum, cookie policy) is published through the same store but
        // asks the reader for nothing, so it must never become a control. Filtered after the
        // registry intersection and before the items are built, so it can neither render as a
        // checkbox nor be counted as required.
        $documents = $documents
            ->filter(static fn (LegalDocument $document): bool => $document->type->isConsentBearing())
            ->sortKeys(SORT_STRING);

        $checklist = [];

        foreach ($documents as $document) {
            // The filter above already removed every non-consent-bearing document, and only
            // those may carry a null sentence — so reaching this line with one means a row that
            // asks for an acceptance it cannot name. Dropping it silently would remove a
            // REQUIRED checkbox from the form and let a registration complete without it, which
            // is the one failure this screen must never have. Named, not skipped.
            if ($document->ui_wording === null) {
                throw new RuntimeException(
                    "Document '{$document->key}' ({$document->locale}) is a {$document->type->value} but carries no acceptance sentence. A document that asks for an acceptance must be able to say what is being accepted; only an informational page may have none."
                );
            }

            $checklist[] = new RegistrationChecklistItem(
                key: $document->key,
                type: $document->type,
                title: $document->title,
                wording: $document->ui_wording,
                version: $document->version,
                locale: $document->locale,
                // Follows the legal basis, never a UI decision: a real consent is voluntary and may
                // never be required (Art. 7(4)); everything else is mandatory.
                required: ! $document->requires_explicit_optin,
                // The render-time fingerprint, so a form that renders hashField() activates the
                // accept-time guard for the version actually shown (opt-in — see the recorder).
                contentHash: self::acceptanceFingerprint($document),
                // Resolved from the DOCUMENT, so the link carries this item's locale rather than the
                // page's — the distinction the class docblock has always insisted on, now made by
                // the package instead of left to every consumer.
                url: $this->urls->for($document),
            );
        }

        // The age attestation is a control the form MUST render, because the rules require it — a
        // form built from this checklist alone would omit the field and could then never pass
        // validation. It is about the PERSON, not a document, so it carries no type, version or
        // locale: there is nothing to freeze, and nothing is recorded for it (Art. 8 DSGVO is an
        // attestation the app gates on; the package does not claim to prove an age).
        if ($this->ageGateEnabled) {
            $checklist[] = new RegistrationChecklistItem(
                key: 'age_confirmed',
                type: null,
                title: '',
                wording: (string) trans('legal-consent::validation.age_required', ['threshold' => $this->ageThreshold]),
                version: '',
                locale: $locale,
                required: true,
            );
        }

        return $checklist;
    }

    /**
     * The active documents for a locale, keyed by document key — the raw material both the checklist
     * and its default-locale fallback are built from.
     *
     * `id` is selected because each row is handed to the host's `document_url` resolver, which
     * receives the MODEL: the most ordinary thing a Laravel host does with one is
     * `route('legal.show', $document)`, implicit route-model binding, and that reads the primary
     * key. Without the column it reads null and the seam raises a UrlGenerationException — a 500 on
     * the sign-up form, while the same closure works on the re-consent gate, whose document set
     * does select it. The presenter carries the identical note for the identical reason.
     *
     * @return Collection<string, LegalDocument>
     */
    private function checklistRows(string $locale): Collection
    {
        return LegalDocument::query()
            ->select(['id', 'key', 'type', 'title', 'ui_wording', 'version', 'locale', 'requires_explicit_optin', 'content_hash'])
            ->where('locale', $locale)
            ->where('is_active', true)
            ->get()
            ->keyBy('key');
    }

    public function record(Model $subject, string $documentKey, ConsentAction $action, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        $document = $this->activeDocument($documentKey, $this->resolveLocale($context, $locale));

        // The court-proof ledger must never hold a legally impossible entry: only a real
        // consent can be withdrawn or declined (Art. 7(3)). Kept here rather than left to the
        // type matrix in append() so the caller still gets the named exception and its wording;
        // the matrix is the backstop for every other pairing.
        if (($action === ConsentAction::Withdrawn || $action === ConsentAction::Declined) && ! $document->type->isWithdrawable()) {
            throw NotWithdrawableException::for($documentKey, $document->type);
        }

        // A confirmation is the SECOND half of a double opt-in, and its whole evidential value is
        // that a first half exists: for advertising e-mail the confirmed double opt-in is the
        // benchmark (§ 7 Abs. 2 UWG with Art. 7 DSGVO), and a lone confirmation row documents a
        // two-step consent that only ever had one step. confirm() refuses it; this is the same
        // refusal on the untyped door, which is the one a consuming application reaches through
        // HasLegalConsents::recordConsent(). The richer checks (window, superseded major) stay in
        // confirm(), which is the supported path and already knows the request row.
        if ($action === ConsentAction::Confirmed) {
            $pending = $this->gate->latestActionFor($subject, $documentKey);

            if (! $pending instanceof LegalConsent || $pending->action !== ConsentAction::OptInRequested) {
                throw NotConfirmableException::noPendingRequest($documentKey);
            }
        }

        return $this->append($subject, $document, $action, $context);
    }

    public function accept(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null, ?string $expectedContentHash = null): LegalConsent
    {
        $document = $this->activeDocument($documentKey, $this->resolveLocale($context, $locale));

        // BEFORE the hash comparison below, and the order is the whole point. That comparison
        // builds `[null, acceptanceFingerprint($document), …]`, and PHP evaluates an array literal
        // eagerly — so the fingerprint is computed on every call, including one that passes no hash
        // at all. acceptanceFingerprint() refuses an informational document, so a guard placed
        // after it never runs: the caller gets that untyped RuntimeException instead, which the
        // JSON API cannot tell from a bug and answers with a 500.
        if (! $document->type->isConsentBearing()) {
            throw NotConsentBearingException::for($documentKey, $document->type);
        }

        // TOCTOU guard: if the subject was shown a hash (captured at render) and the active document
        // has since been re-released, refuse rather than freeze a version they never read
        // (Art. 7(1)). The caller re-shows the current text before recording consent.
        //
        // The comparison covers the acceptance SENTENCE as well as the body. content_hash is taken
        // over the document text alone, but a re-consent form shows only `ui_wording` — so a release
        // that changed nothing but that one sentence (the single string the subject actually reads
        // before ticking) would have slipped through the guard untouched.
        // A bare content_hash is still accepted (the pre-0.5.0 shape a consumer may already send);
        // it just guards the body alone, which is the weaker of the two.
        if (! in_array($expectedContentHash, [null, self::acceptanceFingerprint($document), $document->content_hash], true)) {
            throw DocumentChangedException::for($documentKey, $expectedContentHash, self::acceptanceFingerprint($document));
        }

        return $this->append($subject, $document, $document->type->defaultAcceptAction(), $context);
    }

    public function withdraw(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        $document = $this->documentForTransition($subject, $documentKey, $this->resolveLocale($context, $locale));

        if (! $document->type->isWithdrawable()) {
            throw NotWithdrawableException::for($documentKey, $document->type);
        }

        return $this->append($subject, $document, ConsentAction::Withdrawn, $context);
    }

    public function object(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        $document = $this->documentForTransition($subject, $documentKey, $this->resolveLocale($context, $locale));

        // Guarded exactly as withdraw() is, and for a stronger reason than symmetry. Every public
        // method of the bundled Livewire component is a reachable endpoint whether or not the
        // template renders a button for it, so an unguarded transition is one an authenticated
        // subject can drive with nobody having offered it. What it would leave behind is a row in
        // an APPEND-ONLY ledger asserting a state that does not legally exist — and a wrong row
        // there is worse than a missing one, because it cannot be corrected and every later
        // reader takes it as proof.
        if (! $document->type->isObjectable()) {
            throw NotObjectableException::for($documentKey, $document->type);
        }

        return $this->append($subject, $document, ConsentAction::Objected, $context);
    }

    public function terminate(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        $document = $this->documentForTransition($subject, $documentKey, $this->resolveLocale($context, $locale));

        if (! $document->type->isTerminable()) {
            throw NotTerminableException::for($documentKey, $document->type);
        }

        return $this->append($subject, $document, ConsentAction::Terminated, $context);
    }

    public function requestConfirmation(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        $document = $this->activeDocument($documentKey, $this->resolveLocale($context, $locale));

        // Only a voluntary consent has a double opt-in to run. A contract or a privacy notice is
        // mandatory and is accepted where its full text is presented — a confirmation link cannot
        // present anything, so a two-step flow there would prove less than the one-step one it
        // replaced. The same refusal `grant()` makes, for the same reason.
        if (! $document->type->requiresExplicitOptin()) {
            throw NotGrantableException::for($documentKey, $document->type);
        }

        return $this->append($subject, $document, ConsentAction::OptInRequested, $context);
    }

    public function confirm(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        $document = $this->activeDocument($documentKey, $this->resolveLocale($context, $locale));

        if (! $document->type->requiresExplicitOptin()) {
            throw NotGrantableException::for($documentKey, $document->type);
        }

        // Cross-locale, like every other holding question: consent attaches to a document's
        // identity, not to the language it was read in. The LATEST row is what decides — a request
        // that was already confirmed, or withdrawn since, is not pending any more, and a fold over
        // history would happily confirm it twice.
        $pending = $this->gate->latestActionFor($subject, $documentKey);

        if (! $pending instanceof LegalConsent || $pending->action !== ConsentAction::OptInRequested) {
            throw NotConfirmableException::noPendingRequest($documentKey);
        }

        if ($this->confirmWindowClosed($pending)) {
            throw NotConfirmableException::windowClosed($documentKey, (string) $this->confirmWithin);
        }

        // A new MAJOR between the two halves means the subject would be confirming a text they
        // never read (Art. 7(1)). Major, not any change: that is the line the gate already draws
        // everywhere else — an editorial fix never forces a fresh acceptance, a material change
        // always does — and drawing a second, stricter line here would make the two disagree.
        if ($pending->document_major_version !== $document->major_version) {
            throw NotConfirmableException::superseded($documentKey, $pending->document_major_version, $document->major_version);
        }

        return $this->append($subject, $document, ConsentAction::Confirmed, $context);
    }

    /**
     * Whether a pending request has aged out of the configured confirmation window.
     *
     * Unconfigured means no window, and therefore never closed — a limit nobody chose must not
     * start refusing confirmations an application was already accepting.
     */
    private function confirmWindowClosed(LegalConsent $pending): bool
    {
        if ($this->confirmWithin === null || trim($this->confirmWithin) === '') {
            return false;
        }

        return $pending->accepted_at->add($this->confirmWithin)->isBefore(CarbonImmutable::now());
    }

    public function outstanding(Model $subject, ?string $locale = null): Collection
    {
        return $this->gate->outstandingFor($subject, $locale ?? $this->defaultLocale);
    }

    public function firstAcceptance(Model $subject, ?string $locale = null): Collection
    {
        return $this->gate->firstAcceptanceFor($subject, $locale ?? $this->defaultLocale);
    }

    public function hasCurrent(Model $subject, string $documentKey, ?string $locale = null): bool
    {
        $locale ??= $this->defaultLocale;

        // The active set comes from the SAME cached, publish-invalidated fact the gate reads on
        // every authenticated request, rather than a private `legal_documents` query per call.
        // This method is public API — `HasLegalConsents::hasAcceptedCurrentLegal()` is one line
        // over it — so a consumer asking about three keys in one request paid three uncached
        // lookups for a fact that does not vary between them. Sharing the cache also serves the
        // agreement below: the two methods now read the same active set, not two reads of it.
        $active = app(EnforceableDocumentCache::class)
            ->activeFor($locale)
            ->first(static fn (LegalDocument $document): bool => $document->key === $documentKey);

        if (! $active instanceof LegalDocument) {
            // No published document means the subject holds NOTHING — the honest answer to
            // "does this subject currently hold the active version?". Returning true here made
            // the permission read `if (hasCurrent(...)) { send() }` fire for a typo'd key or a
            // deactivated document. The gate's separate "nothing to block on" question lives in
            // outstandingFor(), not here.
            return false;
        }

        // Held is computed cross-locale and withdrawal/objection-aware, via the SAME fold the
        // gate uses — so hasCurrent() and outstandingFor() can never disagree about one subject.
        //
        // Scoped to the ONE key being asked about, exactly as the gate scopes it to the keys it
        // could block on. The ledger is append-only and grows for the life of the account, so an
        // unfiltered fold made a single-key question cost a scan proportional to how long the
        // subject has been a customer. The fold is per-key independent — the rows are ordered by
        // (document_key, accepted_at, id) and each key folds on its own — so filtering cannot
        // change the answer for the key that survives it.
        return ($this->gate->heldMajorByKey($subject, [$documentKey])[$documentKey] ?? 0) >= $active->major_version;
    }

    public function statusFor(Model $subject, ?string $locale = null): array
    {
        $locale ??= $this->defaultLocale;
        $standing = $this->gate->standingFor($subject);
        $accepted = $standing['held'];
        $pending = $standing['pending'];

        $documents = LegalDocument::query()
            ->select(['key', 'type', 'major_version', 'requires_explicit_optin'])
            ->where('locale', $locale)
            ->where('is_active', true)
            ->get()
            // An informational page has no standing to report: nobody accepts it, so
            // `accepted_major` would sit at 0 forever and `outstanding` — computed from
            // `! requires_explicit_optin`, the same false a contract carries — would be
            // permanently true. A "your agreements" screen built from this map would then show
            // a row for the Impressum that can never be satisfied.
            ->filter(static fn (LegalDocument $document): bool => $document->type->isConsentBearing());

        // The holdings whose document has been retired out from under them. Resolved by the SAME
        // class the settings screen uses, because a status map and the screen built from it
        // disagreeing about which documents a subject stands in is the divergence this package
        // refuses everywhere else. See RetiredHoldings for why the row belongs here at all.
        $retired = new RetiredHoldings()->forSubject(
            $subject,
            $accepted,
            array_values($documents->map(static fn (LegalDocument $document): string => $document->key)->all()),
        );

        /** @var list<array{LegalDocument, bool}> $rows */
        $rows = [];

        foreach ($documents as $document) {
            $rows[] = [$document, false];
        }

        foreach ($retired as $document) {
            $rows[] = [$document, true];
        }

        $status = [];

        foreach ($rows as [$document, $isRetired]) {
            $acceptedMajor = $accepted[$document->key] ?? 0;

            $status[$document->key] = [
                'key' => $document->key,
                'accepted_major' => $acceptedMajor,
                // For a retired row this is the version the SUBJECT accepted, not a current one:
                // there is no current version, and naming a later one would describe a text they
                // never saw.
                'current_major' => $document->major_version,
                'requires_explicit_optin' => $document->requires_explicit_optin,
                // A retired holding is never outstanding — nothing is being enforced, and this flag
                // is what a screen turns into "please accept". See the same reasoning, at length,
                // in ConsentPresenter::settingsFor().
                'outstanding' => ! $isRetired && ! $document->requires_explicit_optin && $acceptedMajor < $document->major_version,
                'retired' => $isRetired,
                // The double opt-in's middle state, and the only one `accepted_major` cannot
                // express: entered but not yet confirmed reads as never entered, so a screen built
                // from this map would invite the subject to enter themselves a second time and a
                // report would count them as having declined.
                'pending_confirmation' => in_array($document->key, $pending, true),
            ];
        }

        return $status;
    }

    public function history(Model $subject): array
    {
        $rows = LegalConsent::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', SubjectKey::for($subject))
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->get();

        $history = [];

        foreach ($rows as $consent) {
            $history[] = [
                'document_key' => $consent->document_key,
                'document_type' => $consent->document_type->value,
                'document_version' => $consent->document_version,
                'document_major_version' => $consent->document_major_version,
                'content_hash' => $consent->content_hash,
                'locale' => $consent->locale,
                'ui_wording' => $consent->ui_wording_snapshot,
                'action' => $consent->action->value,
                'method' => $consent->method->value,
                'source' => $consent->source,
                'accepted_at' => $consent->accepted_at->toIso8601String(),
            ];
        }

        return $history;
    }

    /**
     * Art. 17 erasure, delegated: the column lists, the two-ledger walk and the re-link are
     * ledger mechanics rather than manager logic, and every consumer that rebuilt them would
     * rebuild them differently. See {@see LedgerSubjectEraser}.
     */
    public function forget(Model $subject): SubjectErasure
    {
        return (new LedgerSubjectEraser)->forget($subject);
    }

    private function append(Model $subject, LegalDocument $document, ConsentAction $action, ConsentContext $context): LegalConsent
    {
        // The choke point every write passes through, which is why the class check belongs here and
        // not only on the callers. `record()` takes an arbitrary action from an arbitrary caller —
        // `HasLegalConsents::recordConsent()` is a public trait method on the consumer's own model —
        // and only Withdrawn/Declined were guarded above. Everything else fell through to the
        // insert below, where `ui_wording_snapshot` is NOT NULL and an informational document has
        // no sentence: the caller got a raw SQLSTATE integrity violation.
        if (! $document->type->isConsentBearing()) {
            throw NotConsentBearingException::for($document->key, $document->type);
        }

        // The rest of the compatibility question, in the same place and for the same reason. Each
        // named transition asks its own half before calling here, so a caller who used one keeps
        // its specific message; this arm is what the untyped `record()` never had.
        if (! $this->allowsAction($action, $document->type)) {
            throw IncompatibleConsentActionException::for($document->key, $document->type, $action);
        }

        $subjectId = SubjectKey::for($subject);

        // Checked HERE rather than left to the column, because the column answers differently on
        // every engine: SQLite keeps an over-long key, PostgreSQL and MySQL refuse it with a raw
        // SQLSTATE at the first write of the subject's life. One named refusal at the door is the
        // only version of this a consumer can act on — and it is the door every recorded consent
        // passes through, including the registration recorder and the untyped `record()`.
        if ($subjectId !== null && mb_strlen($subjectId) > SubjectKeyTooLong::MAX_LENGTH) {
            throw SubjectKeyTooLong::for($subject->getMorphClass(), mb_strlen($subjectId));
        }

        $token = $this->tokenFor($subject);

        $attributes = [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subjectId,
            'subject_token' => $token,
            'document_id' => $document->getKey(),
            'document_key' => $document->key,
            'document_type' => $document->type,
            'document_version' => $document->version,
            'document_major_version' => $document->major_version,
            'content_hash' => $document->content_hash,
            'locale' => $document->locale,
            'ui_wording_snapshot' => $document->ui_wording,
            'action' => $action,
            'method' => $context->method,
            'source' => $context->source,
            'ip_address' => $context->ipAddress,
            'user_agent' => $context->userAgent,
            'request_id' => $context->requestId,
            'accepted_at' => CarbonImmutable::now(),
        ];

        $consent = $this->tamperEvidenceEnabled()
            ? $this->appendChained($token, $attributes)
            : DB::transaction(fn (): LegalConsent => LegalConsent::query()->forceCreate($attributes));

        event(match ($action) {
            ConsentAction::Withdrawn => new ConsentWithdrawn($consent),
            ConsentAction::Objected => new ConsentObjected($consent),
            ConsentAction::Terminated => new ConsentTerminated($consent),
            // Named rather than left to the default, and that is the whole point of it: the
            // default is ConsentRecorded, which is what a consuming application provisions on. An
            // unconfirmed opt-in request must not reach that listener — it is a declaration nobody
            // has yet tied to the address it names. The row would say "requested" while every
            // listener heard "granted", which is the silent shape of the exact defect the two-row
            // design exists to prevent.
            ConsentAction::OptInRequested => new ConsentConfirmationRequested($consent),
            default => new ConsentRecorded($consent),
        });

        return $consent;
    }

    /**
     * Append a tamper-chained row, resilient to a concurrent fork.
     *
     * Two appends for one subject can read the same chain tail under READ COMMITTED and try to
     * chain to the same predecessor. The `(subject_token, prev_record_hash)` unique index makes the
     * database reject the second as a fork; we catch that and retry, re-reading the now-advanced
     * tail so the loser chains on cleanly instead of forking. Without the retry the loser would
     * surface a violation to the caller; without the index the fork would persist and the verifier
     * would report it as tampering.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function appendChained(string $token, array $attributes): LegalConsent
    {
        $chain = new LedgerHashChain;

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($token, $attributes, $chain): LegalConsent {
                    // Chain onto the subject's last ALREADY-CHAINED row. Rows written before
                    // tamper-evidence was enabled carry a NULL link and are skipped, so the first
                    // chained row for such a subject starts at genesis — matching the verifier's
                    // walk, which begins each subject at genesis.
                    $attributes['prev_record_hash'] = $chain->linkFor($this->latestChainedRow($token));

                    // forceFill()+save(): the attributes are a runtime-assembled array<string, mixed>
                    // (prev_record_hash is set here), which create()'s shaped-array type would reject —
                    // and the model is guarded against mass assignment ($guarded = ['*']), so filling a
                    // proof row is deliberately something only this curated write path may do.
                    $consent = new LegalConsent;
                    $consent->forceFill($attributes)->save();

                    return $consent;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_CHAIN_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * The subject's last already-chained row (or null when none is chained yet). `lockForUpdate`
     * serializes contending appends on Postgres/MySQL to reduce retries (it is a no-op on SQLite,
     * which serializes writers anyway); the unique index is the actual correctness guarantee.
     *
     * A `protected` seam: overriding it lets a test return the stale tail a concurrent writer sees
     * and drive the fork-retry deterministically, which no single-connection test could otherwise
     * provoke.
     */
    protected function latestChainedRow(string $token): ?object
    {
        return DB::table('legal_consents')
            ->where('subject_token', $token)
            ->whereNotNull('prev_record_hash')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function tamperEvidenceEnabled(): bool
    {
        return filter_var(config('legal-consent.tamper_evidence', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * Which document types each ledger action may legally be written against.
     *
     * The predicates are the ones {@see DocumentType} already publishes, so this table asserts no
     * law of its own — it puts the SAME rule on the untyped door that each named transition
     * applies to itself. `deemed_accepted` is spelled out rather than derived because there is no
     * predicate for it: silence is deemed acceptance only for a contract change (§ 308 Nr. 5 BGB;
     * BGH XI ZR 26/20), which is exactly what the publisher refuses to classify any other way.
     *
     * `re_accepted` and `parental` are allowed against every type that binds anyone. Re-acceptance
     * after a material change is meaningful for all three, and a guardian acts for a minor both
     * where a consent is asked for (Art. 8) and where a contract is (§ 107 BGB) — refusing either
     * would refuse a lawful row, which is the more expensive mistake in an append-only ledger.
     */
    private function allowsAction(ConsentAction $action, DocumentType $type): bool
    {
        return match ($action) {
            ConsentAction::Granted,
            ConsentAction::Withdrawn,
            ConsentAction::Declined,
            ConsentAction::OptInRequested,
            ConsentAction::Confirmed => $type->requiresExplicitOptin(),
            ConsentAction::Acknowledged => $type->isMandatory(),
            ConsentAction::Objected => $type->isObjectable(),
            ConsentAction::Terminated => $type->isTerminable(),
            ConsentAction::DeemedAccepted => $type === DocumentType::ContractTerms,
            ConsentAction::ReAccepted, ConsentAction::Parental => $type->isConsentBearing(),
        };
    }

    /**
     * The document a TRANSITION out of a holding — a withdrawal, an objection, a termination —
     * is recorded against.
     *
     * The active version when there is one. When there is not, the version the subject actually
     * accepted, read from their own ledger row. Art. 7(3) requires withdrawal to be as easy as
     * giving consent was, and retiring a document — `is_active = false`, which is the supported
     * retirement route, the column being in {@see LegalDocument::MUTABLE_AFTER_PUBLISH} — must not
     * be able to close that door: the acceptance stays in the ledger and the gate keeps folding it
     * as held, so the subject would be bound by a record they can no longer act on while the
     * document has already vanished from every screen.
     *
     * Nothing is invented on that path. The ledger row names the exact frozen version, and
     * retiring a document deactivates it rather than deleting it, so the row it points at is still
     * on file with its type, its text and its hash. The type guards keep working unchanged,
     * because the type is read from that row.
     */
    private function documentForTransition(Model $subject, string $documentKey, string $locale): LegalDocument
    {
        $document = $this->publishedDocument($documentKey, $locale) ?? $this->acceptedVersionFor($subject, $documentKey);

        if (! $document instanceof LegalDocument) {
            throw LegalDocumentNotFound::forSource($documentKey, $locale, LegalDocumentNotFound::PUBLISHED_LOOKUP);
        }

        return $document;
    }

    /**
     * The version this subject last acted on for a document key, or null when they never did.
     *
     * Cross-locale, like every other holding question: an acceptance attaches to a document's
     * identity, not to the language it was read in.
     */
    private function acceptedVersionFor(Model $subject, string $documentKey): ?LegalDocument
    {
        $latest = $this->gate->latestActionFor($subject, $documentKey);

        if (! $latest instanceof LegalConsent) {
            return null;
        }

        if ($latest->document_id !== null) {
            $document = LegalDocument::query()->whereKey($latest->document_id)->first();

            if ($document instanceof LegalDocument) {
                return $document;
            }
        }

        // A row whose `document_id` was never set, or whose version row was re-inserted under a
        // fresh id by a lawful rewrite: the denormalized (key, locale, version) triple identifies
        // the same version just as exactly, which is why migration 000008 could drop the foreign
        // key in the first place.
        return LegalDocument::query()
            ->where('key', $latest->document_key)
            ->where('locale', $latest->locale)
            ->where('version', $latest->document_version)
            ->first();
    }

    private function activeDocument(string $documentKey, string $locale): LegalDocument
    {
        $document = $this->publishedDocument($documentKey, $locale);

        if (! $document instanceof LegalDocument) {
            throw LegalDocumentNotFound::forSource($documentKey, $locale, LegalDocumentNotFound::PUBLISHED_LOOKUP);
        }

        return $document;
    }

    /**
     * The active version for (key, locale), falling back to the configured fallback locale when
     * the document is not published in the requested one (config `fallback_locale`) — a graceful
     * default for multilingual apps rather than a hard failure. The recorded row carries the
     * fallback's locale.
     */
    private function publishedDocument(string $documentKey, string $locale): ?LegalDocument
    {
        $document = $this->activeDocumentIn($documentKey, $locale);

        if ($document instanceof LegalDocument) {
            return $document;
        }

        $fallback = $this->fallbackLocale();

        if ($fallback !== '' && $fallback !== $locale) {
            return $this->activeDocumentIn($documentKey, $fallback);
        }

        return null;
    }

    private function activeDocumentIn(string $documentKey, string $locale): ?LegalDocument
    {
        return LegalDocument::query()
            ->where('key', $documentKey)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();
    }

    private function fallbackLocale(): string
    {
        $value = config('legal-consent.fallback_locale');

        return is_string($value) ? $value : '';
    }

    private function resolveLocale(ConsentContext $context, ?string $locale): string
    {
        return $locale ?? $context->locale ?? $this->defaultLocale;
    }

    private function tokenFor(Model $subject): string
    {
        // Shared with the notice-delivery ledger so both carry the SAME pseudonym for a subject.
        return new SubjectToken()->forSubject($subject);
    }

    /**
     * What the subject was shown, as one comparable value: the document body's content hash folded
     * with the acceptance sentence rendered next to it.
     *
     * Public and static so every capture point (the bundled form, a consumer's own screen, the JSON
     * API) derives it identically — a guard whose two sides compute the value differently is not a
     * guard. A bare `content_hash` is still accepted for the body-only case, so an existing consumer
     * passing one keeps working.
     *
     * Takes the PublishedDocument too, because that is the only type the documented public read
     * path hands out ({@see ConsentManager::published()}). Without it a consumer rendering its own
     * page had exactly three options, all wrong: rebuild this hash by hand (the second
     * implementation this docblock rules out), record `content_hash` alone (which does NOT cover
     * `ui_wording` — a hash of a different text than the one shown), or reach past the DTO to the
     * Eloquent model and leave the read path the reader calls the only one to use. One function,
     * both types, so the two sides cannot drift.
     */
    public static function acceptanceFingerprint(LegalDocument|PublishedDocument $document): string
    {
        [$contentHash, $uiWording] = $document instanceof LegalDocument
            ? [$document->content_hash, $document->ui_wording]
            : [$document->contentHash, $document->uiWording];

        // A missing sentence means an `informational` page, and fingerprinting one is a
        // category error rather than an edge case: nobody ever accepts it, so there is no
        // acceptance to bind a hash to. Coercing the null to '' would answer anyway -- with a
        // fingerprint for a consent that cannot exist, which a caller would then store.
        if ($uiWording === null) {
            throw new RuntimeException(
                'Cannot build an acceptance fingerprint for an informational document: it carries no acceptance sentence because it asks the reader for nothing. Nothing accepts an informational page, so nothing needs to fingerprint one.'
            );
        }

        return hash('sha256', $contentHash."\x1f".$uiWording);
    }
}
