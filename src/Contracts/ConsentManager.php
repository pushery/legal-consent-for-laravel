<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\ConsentContext;

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
     */
    public function accept(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent;

    public function withdraw(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent;

    /**
     * Record a Widerspruch: the subject objected to a change (rebutting a deemed-consent
     * fiction, § 308 Nr. 5 lit. a BGB) or objected to legitimate-interest processing
     * (Art. 21). Fires ConsentObjected so the app can stop the processing where required.
     */
    public function object(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent;

    /**
     * Record that the subject exercised a free right to terminate before a change took
     * effect (§ 675g / § 327r Abs. 3 BGB / P2B). Fires ConsentTerminated so the app can end
     * the contract; the package only records the provable ledger entry.
     */
    public function terminate(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent;

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
     * @return array<string, array{key: string, accepted_major: int, current_major: int, requires_explicit_optin: bool, outstanding: bool}>
     */
    public function statusFor(Model $subject, ?string $locale = null): array;

    /**
     * The subject's full, chronological consent history — an Art. 15/20 export payload.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Model $subject): array;
}
