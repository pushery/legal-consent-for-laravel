<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Testing;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use Pushery\LegalConsent\Content\PublishedDocument;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\ConsentContext;
use Pushery\LegalConsent\Support\RegistrationChecklistItem;
use Pushery\LegalConsent\Support\SubjectErasure;

/**
 * An in-memory {@see ConsentManager} for a CONSUMING application's tests. Install it with
 * `Consent::fake()`; nothing it does touches a database.
 *
 * It exists because this package's own suite could never notice the gap it fills. Every test in
 * here runs against a real schema, so the interface is always satisfied by the real manager —
 * while a consuming app that wanted to test "the register form shows the right checkbox" had to
 * either migrate this package's tables into its own test database or hand-roll a stub of every
 * method on it, and a hand-rolled stub silently rots on the next method added here.
 *
 * READ DEFAULTS DESCRIBE A FULLY-CONSENTED SUBJECT: nothing outstanding, `hasCurrent()` true,
 * empty status and history, nothing published. That direction is deliberate. A consuming test about
 * something else entirely — a checkout, a profile update — must not start failing because a consent
 * gate it never mentioned decided the subject owes a document. A test that cares declares what it
 * cares about with `owes()`, `publishes()` or `checklistIs()`; a test that does not gets out of the
 * gate's way.
 *
 * WRITES ARE RECORDED, NEVER PERFORMED. `record()` and friends return an UNSAVED LegalConsent
 * carrying the attributes they were called with, so calling code that reads the returned model
 * keeps working, while `$model->exists` stays false — the honest answer, since nothing was written.
 */
final class ConsentFake implements ConsentManager
{
    /** @var list<RecordedConsent> */
    private array $recorded = [];

    /** @var list<Model> every subject handed to `forget()`, in order. */
    private array $forgotten = [];

    /** @var array<string, list<string>> subject identity => document keys still owed */
    private array $owed = [];

    /** @var array<string, PublishedDocument> "key|locale" => the published document */
    private array $published = [];

    /** @var list<RegistrationChecklistItem> */
    private array $checklist = [];

    /** @var array<string, array{key: string, accepted_major: int, current_major: int, requires_explicit_optin: bool, outstanding: bool, pending_confirmation: bool, retired: bool, accepted_version: string|null, accepted_at: string|null}> */
    private array $status = [];

    /** @var list<array<string, mixed>> */
    private array $history = [];

    // ---------------------------------------------------------------- arrangement

    /**
     * Declare the documents a subject still owes. `outstanding()` returns them and `hasCurrent()`
     * answers false for exactly these keys — the two reads a consent gate makes, kept consistent
     * so a test cannot arrange a subject who both owes a document and holds it.
     */
    public function owes(Model $subject, string ...$documentKeys): self
    {
        $this->owed[$this->identify($subject)] = array_values($documentKeys);

        return $this;
    }

    /** Declare what `published()` answers for one (key, locale). */
    public function publishes(PublishedDocument $document): self
    {
        $this->published["{$document->key}|{$document->locale}"] = $document;

        return $this;
    }

    /** Declare what `registrationChecklist()` answers. */
    public function checklistIs(RegistrationChecklistItem ...$items): self
    {
        $this->checklist = array_values($items);

        return $this;
    }

    /**
     * The two version keys are OPTIONAL here and filled in when they are left out, which is the
     * only shape that keeps this fake honest.
     *
     * `statusFor()` gained `accepted_version` and `accepted_at`. A fake that handed back exactly
     * what a test wrote would then return rows production never returns — and the whole value of
     * a fake is that code passing against it passes against the real manager. Requiring the new
     * keys instead would break every existing `statusIs()` call for two values most tests do not
     * care about. Filling them is the third option and the right one.
     *
     * @param  array<string, array{key: string, accepted_major: int, current_major: int, requires_explicit_optin: bool, outstanding: bool, pending_confirmation: bool, retired: bool, accepted_version?: string|null, accepted_at?: string|null}>  $status
     */
    public function statusIs(array $status): self
    {
        $this->status = array_map(
            static fn (array $row): array => $row + ['accepted_version' => null, 'accepted_at' => null],
            $status,
        );

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $history
     */
    public function historyIs(array $history): self
    {
        $this->history = $history;

        return $this;
    }

    // ---------------------------------------------------------------- assertions

    /** @return list<RecordedConsent> */
    public function recorded(?Model $subject = null, ?string $documentKey = null, ?ConsentAction $action = null): array
    {
        return array_values(array_filter(
            $this->recorded,
            static fn (RecordedConsent $entry): bool => (! $subject instanceof Model || $entry->isFor($subject))
                && ($documentKey === null || $entry->documentKey === $documentKey)
                && (! $action instanceof ConsentAction || $entry->action === $action),
        ));
    }

    public function assertRecorded(Model $subject, string $documentKey, ?ConsentAction $action = null): void
    {
        Assert::assertNotEmpty(
            $this->recorded($subject, $documentKey, $action),
            $this->describeMiss('Expected', $subject, $documentKey, $action),
        );
    }

    public function assertNotRecorded(Model $subject, string $documentKey, ?ConsentAction $action = null): void
    {
        Assert::assertEmpty(
            $this->recorded($subject, $documentKey, $action),
            $this->describeMiss('Did not expect', $subject, $documentKey, $action),
        );
    }

    public function assertAccepted(Model $subject, string $documentKey): void
    {
        // Acceptance is recorded as GRANTED for a real consent, ACKNOWLEDGED for a mandatory
        // document, and RE_ACCEPTED when a new version is accepted — which one the real manager
        // picks depends on the document's type and on history, and a consuming test has no reason
        // to know either. Accepting all three is what makes this assertion mean "the subject
        // agreed" rather than "the subject agreed to a document of this class, the first time".
        //
        // DEEMED_ACCEPTED is deliberately NOT one of them: silence counting as acceptance is the
        // legal fiction of § 308 Nr. 5, not an act of the subject, and a test asserting "the user
        // accepted" must not be satisfied by the user having said nothing.
        //
        // CONFIRMED is one of them: the second half of a double opt-in is the act that makes the
        // consent held, and it is the row an Art. 7(1) demand is answered with. OPT_IN_REQUESTED is
        // not, for the same reason DEEMED_ACCEPTED is not — it is a declaration awaiting proof that
        // the person who made it controls the address.
        $accepting = [ConsentAction::Granted, ConsentAction::Acknowledged, ConsentAction::ReAccepted, ConsentAction::Confirmed];
        $matches = array_merge(...array_map(
            fn (ConsentAction $action): array => $this->recorded($subject, $documentKey, $action),
            $accepting,
        ));

        Assert::assertNotEmpty($matches, $this->describeMiss('Expected an acceptance of', $subject, $documentKey));
    }

    public function assertWithdrawn(Model $subject, string $documentKey): void
    {
        $this->assertRecorded($subject, $documentKey, ConsentAction::Withdrawn);
    }

    public function assertNothingRecorded(): void
    {
        Assert::assertSame([], $this->recorded, 'Expected no consent to be recorded, but '.count($this->recorded).' was/were.');
    }

    public function assertRecordedCount(int $expected): void
    {
        Assert::assertCount($expected, $this->recorded);
    }

    // ---------------------------------------------------------------- writes

    public function record(Model $subject, string $documentKey, ConsentAction $action, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        return $this->capture($subject, $documentKey, $action, $context, $locale);
    }

    public function accept(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null, ?string $expectedContentHash = null): LegalConsent
    {
        // Granted, not Acknowledged: the fake does not know the document's type, and guessing would
        // make assertAccepted() the only honest assertion anyway — which is why that one accepts
        // both. The hash is kept so a test can assert the render-time value was carried through.
        return $this->capture($subject, $documentKey, ConsentAction::Granted, $context, $locale, $expectedContentHash);
    }

    public function withdraw(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        return $this->capture($subject, $documentKey, ConsentAction::Withdrawn, $context, $locale);
    }

    public function object(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        return $this->capture($subject, $documentKey, ConsentAction::Objected, $context, $locale);
    }

    public function terminate(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        return $this->capture($subject, $documentKey, ConsentAction::Terminated, $context, $locale);
    }

    public function requestConfirmation(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        return $this->capture($subject, $documentKey, ConsentAction::OptInRequested, $context, $locale);
    }

    public function confirm(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        // The real manager refuses a confirmation with no pending request. The fake does NOT, and
        // that is deliberate: it captures calls so a consuming application can assert its own flow,
        // and re-implementing the refusal here would be a second copy of a rule that then drifts
        // from the one that matters. Assert the pair with recorded()/assertRecorded().
        return $this->capture($subject, $documentKey, ConsentAction::Confirmed, $context, $locale);
    }

    // ---------------------------------------------------------------- reads

    /**
     * The documents a test arranged with `owes()`, carrying the same attribute surface the real
     * manager hands out.
     *
     * HYDRATED, not `new` + `forceFill`, and that is the whole point of the method. The real
     * manager returns models the gate hydrated from a narrow SELECT: `exists` is true, the columns
     * the contract names are loaded, and every other column is MISSING — which under
     * `Model::shouldBeStrict()` throws on access. A fake built with `new` has `exists === false`,
     * and the framework's check reads `$this->exists` first, so such a model answers null to
     * everything and throws at nothing. That is the dangerous direction: the consuming test would
     * be green on exactly the read that 500s in production.
     *
     * The values are stand-ins — the fake has no database to have read them from — but the SHAPE
     * is the one {@see ConsentManager::outstanding()} names, and this package's suite holds the
     * two implementations against that list rather than against each other.
     *
     * @return Collection<int, LegalDocument>
     */
    public function outstanding(Model $subject, ?string $locale = null): Collection
    {
        $locale ??= 'de';
        $keys = $this->owed[$this->identify($subject)] ?? [];

        /** @var Collection<int, LegalDocument> $documents */
        // array_map over TWO arrays reindexes on its own, so the arranged keys arrive as a list
        // even when `owes()` was called with named arguments.
        $documents = LegalDocument::hydrate(array_map(
            fn (int $index, string $key): array => [
                'id' => $index + 1,
                'key' => $key,
                'locale' => $locale,
                // Raw column values, not enum instances: hydrate() sets the attributes the way the
                // driver would, and the model's casts turn them back on the way out.
                'type' => DocumentType::ContractTerms->value,
                'major_version' => 1,
                'version' => '1.0.0',
                'title' => $key,
                'ui_wording' => $this->acceptanceWordingFor($key, $locale),
                'content_hash' => hash('sha256', $key.'|'.$locale),
                // An arranged document is mandatory and still owed — the two reads a gate makes,
                // kept consistent with hasCurrent() answering false for exactly these keys.
                'requires_explicit_optin' => false,
                'requires_reconsent' => true,
                'notice_mode' => NoticeMode::ActiveReconsent->value,
                'announce_from' => null,
                'enforce_from' => null,
                'objection_deadline' => null,
                'offers_termination' => false,
                'is_active' => true,
            ],
            array_keys($keys),
            $keys,
        ));

        return $documents;
    }

    /**
     * The same arranged set as {@see outstanding()}, and the fake says so rather than inventing a
     * distinction it cannot compute.
     *
     * In production the two reads answer different questions and can differ: `outstanding()`
     * filters on the notice mode of a version CHANGE, while this one asks whether the subject ever
     * accepted anything at all. A double has no published rows and no notice modes, so it cannot
     * derive that split — arranging one set and answering both from it is the honest shape.
     * Arrange with `owes()` and assert on whichever read the screen under test uses.
     */
    public function firstAcceptance(Model $subject, ?string $locale = null): Collection
    {
        return $this->outstanding($subject, $locale);
    }

    /**
     * The acceptance sentence for an arranged document, resolved the way the render pipeline
     * resolves it: the document's own line, then the generic one. A consuming test that renders
     * the gate therefore sees a real sentence rather than an empty label.
     */
    private function acceptanceWordingFor(string $key, string $locale): string
    {
        foreach (["legal-consent::wording.{$key}", 'legal-consent::wording.default'] as $line) {
            $translated = trans($line, [], $locale);

            if (is_string($translated) && $translated !== $line && trim($translated) !== '') {
                return $translated;
            }
        }

        return '';
    }

    public function hasCurrent(Model $subject, string $documentKey, ?string $locale = null): bool
    {
        return ! in_array($documentKey, $this->owed[$this->identify($subject)] ?? [], true);
    }

    /**
     * @return array<string, array{key: string, accepted_major: int, current_major: int, requires_explicit_optin: bool, outstanding: bool, pending_confirmation: bool, retired: bool, accepted_version: string|null, accepted_at: string|null}>
     */
    public function statusFor(Model $subject, ?string $locale = null): array
    {
        return $this->status;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(Model $subject): array
    {
        return $this->history;
    }

    /**
     * Records the call and answers with zeros rather than mutating anything.
     *
     * A fake exists so a test can assert an app CALLED the erasure; making it also simulate a
     * ledger rewrite would mean reimplementing the chain walk in test code, and a second
     * implementation of the thing under test is worth less than no implementation. Assert against
     * the real manager when the rewrite itself is the subject.
     */
    public function forget(Model $subject): SubjectErasure
    {
        $this->forgotten[] = $subject;

        return new SubjectErasure;
    }

    /** Was this subject handed to `forget()`? */
    public function assertForgotten(Model $subject): void
    {
        $matched = array_filter(
            $this->forgotten,
            fn (Model $seen): bool => $seen::class === $subject::class && $seen->getKey() === $subject->getKey(),
        );

        Assert::assertNotEmpty($matched, 'Expected the subject to have been forgotten, but forget() was never called for them.');
    }

    /** `forget()` was NOT called for this subject — the half that catches an over-eager erasure. */
    public function assertNotForgotten(Model $subject): void
    {
        $matched = array_filter(
            $this->forgotten,
            fn (Model $seen): bool => $seen::class === $subject::class && $seen->getKey() === $subject->getKey(),
        );

        Assert::assertEmpty($matched, 'Expected the subject NOT to have been forgotten, but forget() was called for them.');
    }

    public function published(string $documentKey, ?string $locale = null): ?PublishedDocument
    {
        // Mirrors the real read path, which has NO locale fallback on purpose: a page shows the
        // text of the locale it claims, or nothing. A fake that fell back would let a consumer
        // build a page that works in tests and renders the wrong language in production.
        return $this->published[$documentKey.'|'.($locale ?? 'de')] ?? null;
    }

    /**
     * @return list<RegistrationChecklistItem>
     */
    public function registrationChecklist(?string $locale = null): array
    {
        return $this->checklist;
    }

    // ---------------------------------------------------------------- internals

    private function capture(Model $subject, string $documentKey, ConsentAction $action, ConsentContext $context, ?string $locale, ?string $expectedContentHash = null): LegalConsent
    {
        $this->recorded[] = new RecordedConsent(
            subject: $subject,
            subjectType: $subject::class,
            subjectKey: RecordedConsent::keyOf($subject),
            documentKey: $documentKey,
            action: $action,
            context: $context,
            locale: $locale,
            expectedContentHash: $expectedContentHash,
        );

        $consent = new LegalConsent;

        // forceFill, because the model guards everything: the ledger is append-only and mass
        // assignment into it is exactly what that guard is for. An unsaved instance is the honest
        // return value — `exists` stays false, so calling code that persists or reloads it fails
        // loudly here rather than quietly asserting against a row that was never written.
        $consent->forceFill([
            'subject_type' => $subject::class,
            'subject_id' => RecordedConsent::keyOf($subject),
            'document_key' => $documentKey,
            'action' => $action,
            'locale' => $locale,
        ]);

        return $consent;
    }

    private function identify(Model $subject): string
    {
        return $subject::class.'|'.(RecordedConsent::keyOf($subject) ?? 'null');
    }

    private function describeMiss(string $lead, Model $subject, string $documentKey, ?ConsentAction $action = null): string
    {
        $what = $action instanceof ConsentAction ? "a {$action->value} on '{$documentKey}'" : "any entry for '{$documentKey}'";
        $seen = $this->recorded === []
            ? 'nothing was recorded'
            : 'recorded: '.implode(', ', array_map(
                static fn (RecordedConsent $entry): string => "{$entry->documentKey}/{$entry->action->value}",
                $this->recorded,
            ));

        return "{$lead} {$what} for ".$subject::class.' #'.(RecordedConsent::keyOf($subject) ?? 'null').", but {$seen}.";
    }
}
