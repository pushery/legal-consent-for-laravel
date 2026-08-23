<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Pushery\LegalConsent\Content\PublishedDocument;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\ConsentContext;
use Pushery\LegalConsent\Support\RegistrationChecklistItem;

/**
 * The headless core for recording and querying consent. Works on any Eloquent model as
 * a polymorphic subject; every write is a single append-only ledger entry.
 */
interface ConsentManager
{
    public function record(Model $subject, string $documentKey, ConsentAction $action, ConsentContext $context, ?string $locale = null): LegalConsent;

    /**
     * Record acceptance using the document type's natural action (granted for a real
     * consent, acknowledged for a mandatory document).
     *
     * Pass `$expectedContentHash` — the `content_hash` the subject was actually shown, captured at
     * render time — to guard against a mid-session release: if the active document no longer hashes
     * to it, a `DocumentChangedException` (409) is thrown instead of freezing a version the subject
     * never read (Art. 7(1)). Null skips the check.
     */
    public function accept(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null, ?string $expectedContentHash = null): LegalConsent;

    public function withdraw(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent;

    /**
     * Record a Widerspruch: the subject objected to a change (rebutting a deemed-consent
     * fiction, § 308 Nr. 5 lit. a BGB) or objected to legitimate-interest processing
     * (Art. 21). Fires ConsentObjected so the app can stop the processing where required.
     *
     * Throws NotObjectableException for a real consent (which is withdrawn, not objected to) and
     * for an informational page (which binds nobody).
     */
    public function object(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent;

    /**
     * Record that the subject exercised a free right to terminate before a change took
     * effect (§ 675g / § 327r Abs. 3 BGB / P2B). Fires ConsentTerminated so the app can end
     * the contract; the package only records the provable ledger entry.
     *
     * Throws NotTerminableException for anything that is not a contract — a privacy notice is
     * information, a consent is withdrawn, and an informational page binds nobody.
     */
    public function terminate(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent;

    /**
     * Record the FIRST half of a double opt-in: the subject entered themselves, and nobody has yet
     * shown that whoever did so controls the address.
     *
     * The row does NOT make the consent held — that is the point of it. For advertising e-mail the
     * confirmed double opt-in is the German benchmark (§ 7 Abs. 2 UWG with Art. 7 DSGVO) and the
     * burden of proof lies with the controller (Art. 7(1)), so an unconfirmed entry must not read
     * as a consent anywhere: not in `statusFor()`, not in the gate, not in an Art. 15 export.
     *
     * Throws NotGrantableException for anything that is not a voluntary consent: a contract is
     * agreed where its full text is presented, and a confirmation link presents nothing.
     */
    public function requestConfirmation(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent;

    /**
     * Record the SECOND half: the subject followed the confirmation link, so the declaration and
     * the address belong to the same person. THIS is the row that makes the consent held.
     *
     * Requires a pending request as the subject's latest row for the document. Throws
     * NotConfirmableException when there is none, when the configured confirmation window has
     * closed, or when a new MAJOR version was published in between — confirming that one would
     * freeze a text the subject never read.
     */
    public function confirm(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent;

    /**
     * The mandatory documents this subject still owes acceptance for.
     *
     * @return Collection<int, LegalDocument>
     */
    public function outstanding(Model $subject, ?string $locale = null): Collection;

    /**
     * Whether the subject currently holds the active major version of a document.
     */
    public function hasCurrent(Model $subject, string $documentKey, ?string $locale = null): bool;

    /**
     * A per-document status map for the subject.
     *
     * @return array<string, array{key: string, accepted_major: int, current_major: int, requires_explicit_optin: bool, outstanding: bool, pending_confirmation: bool}>
     */
    public function statusFor(Model $subject, ?string $locale = null): array;

    /**
     * The subject's full, chronological consent history — an Art. 15/20 export payload.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Model $subject): array;

    /**
     * The PUBLISHED document a public page must render: the frozen row's verbatim bytes and
     * stored hash — the same text the ledger proves the subject accepted. Null when nothing is
     * published for that (key, locale); it never throws, so a public page can render an "in
     * preparation" shell instead of failing.
     *
     * This is the read path. Rendering the configured SOURCE instead (see LegalSourceRenderer)
     * shows text that may have drifted from the published row, which silently breaks the one
     * promise the ledger makes.
     */
    public function published(string $documentKey, ?string $locale = null): ?PublishedDocument;

    /**
     * The consent controls a registration form must render, derived from what is actually
     * PUBLISHED rather than from a list the form hardcodes — so renaming, unpublishing, or
     * publishing a document in another language cannot leave the form silently wrong.
     *
     * @return list<RegistrationChecklistItem>
     */
    public function registrationChecklist(?string $locale = null): array;
}
