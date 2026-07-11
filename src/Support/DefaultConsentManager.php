<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Events\ConsentRecorded;
use Pushery\LegalConsent\Events\ConsentWithdrawn;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Exceptions\NotWithdrawableException;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * The default headless consent core. Every record/withdraw appends exactly one
 * immutable ledger entry, snapshotting the active version's proof fields plus the
 * server-side context, then fires the matching event.
 */
final readonly class DefaultConsentManager implements ConsentManager
{
    public function __construct(
        private ConsentGate $gate,
        private string $defaultLocale = 'de',
    ) {}

    public function record(Model $subject, string $documentKey, ConsentAction $action, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        $document = $this->activeDocument($documentKey, $this->resolveLocale($context, $locale));

        // The court-proof ledger must never hold a legally impossible entry: only a real
        // consent can be withdrawn or declined (Art. 7(3)).
        if (($action === ConsentAction::Withdrawn || $action === ConsentAction::Declined) && ! $document->type->isWithdrawable()) {
            throw NotWithdrawableException::for($documentKey, $document->type);
        }

        return $this->append($subject, $document, $action, $context);
    }

    public function accept(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        $document = $this->activeDocument($documentKey, $this->resolveLocale($context, $locale));

        return $this->append($subject, $document, $document->type->defaultAcceptAction(), $context);
    }

    public function withdraw(Model $subject, string $documentKey, ConsentContext $context, ?string $locale = null): LegalConsent
    {
        $document = $this->activeDocument($documentKey, $this->resolveLocale($context, $locale));

        if (! $document->type->isWithdrawable()) {
            throw NotWithdrawableException::for($documentKey, $document->type);
        }

        return $this->append($subject, $document, ConsentAction::Withdrawn, $context);
    }

    public function outstanding(Model $subject, ?string $locale = null): Collection
    {
        return $this->gate->outstandingFor($subject, $locale ?? $this->defaultLocale);
    }

    public function hasCurrent(Model $subject, string $documentKey, ?string $locale = null): bool
    {
        $locale ??= $this->defaultLocale;

        $active = LegalDocument::query()
            ->select(['major_version'])
            ->where('key', $documentKey)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();

        if (! $active instanceof LegalDocument) {
            return true; // nothing enforceable to accept
        }

        // The LATEST action decides — a monotonic max cannot see a later withdrawal.
        $latest = $this->gate->latestActionFor($subject, $documentKey, $locale);

        return $latest instanceof LegalConsent
            && $latest->action->isAccepting()
            && $latest->document_major_version >= $active->major_version;
    }

    public function statusFor(Model $subject, ?string $locale = null): array
    {
        $locale ??= $this->defaultLocale;
        $accepted = $this->gate->highestAcceptedMajors($subject, $locale);

        $documents = LegalDocument::query()
            ->select(['key', 'major_version', 'requires_explicit_optin'])
            ->where('locale', $locale)
            ->where('is_active', true)
            ->get();

        $status = [];

        foreach ($documents as $document) {
            $acceptedMajor = $accepted[$document->key] ?? 0;

            $status[$document->key] = [
                'key' => $document->key,
                'accepted_major' => $acceptedMajor,
                'current_major' => $document->major_version,
                'requires_explicit_optin' => $document->requires_explicit_optin,
                'outstanding' => ! $document->requires_explicit_optin && $acceptedMajor < $document->major_version,
            ];
        }

        return $status;
    }

    public function history(Model $subject): array
    {
        $rows = LegalConsent::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
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

    private function append(Model $subject, LegalDocument $document, ConsentAction $action, ConsentContext $context): LegalConsent
    {
        $token = $this->tokenFor($subject);

        $consent = DB::transaction(function () use ($subject, $document, $action, $context, $token): LegalConsent {
            $attributes = [
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
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

            // Optional tamper-evidence: chain this row to the subject's previous one. Read
            // inside the transaction so the link is taken against a consistent tail.
            if ($this->tamperEvidenceEnabled()) {
                $previous = DB::table('legal_consents')
                    ->where('subject_token', $token)
                    ->orderByDesc('id')
                    ->first();

                $attributes['prev_record_hash'] = (new LedgerHashChain)->linkFor($previous);
            }

            return LegalConsent::query()->create($attributes);
        });

        event($action === ConsentAction::Withdrawn ? new ConsentWithdrawn($consent) : new ConsentRecorded($consent));

        return $consent;
    }

    private function tamperEvidenceEnabled(): bool
    {
        return filter_var(config('legal-consent.tamper_evidence', false), FILTER_VALIDATE_BOOL);
    }

    private function activeDocument(string $documentKey, string $locale): LegalDocument
    {
        $document = $this->activeDocumentIn($documentKey, $locale);

        // Fall back to the configured fallback locale when the document is not published in
        // the requested one (config `fallback_locale`) — a graceful default for multilingual
        // apps, rather than a hard failure. The recorded row carries the fallback's locale.
        if (! $document instanceof LegalDocument) {
            $fallback = $this->fallbackLocale();

            if ($fallback !== '' && $fallback !== $locale) {
                $document = $this->activeDocumentIn($documentKey, $fallback);
            }
        }

        if (! $document instanceof LegalDocument) {
            throw LegalDocumentNotFound::forSource($documentKey, $locale, 'legal_documents');
        }

        return $document;
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
        $existing = LegalConsent::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->whereNotNull('subject_token')
            ->value('subject_token');

        return is_string($existing) ? $existing : (string) Str::uuid();
    }
}
