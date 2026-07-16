<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Data for the non-blocking countdown banner shown DURING the grace period — after a
 * material change has been announced (announce_from) but before it is enforced
 * (enforce_from). This satisfies § 308 Nr. 5 BGB (reasonable period + notice of the
 * consequence) and lets the subject cancel/delete before the change takes effect.
 *
 * It returns only DATA — the optional Blade stub renders it (no framework UI here).
 */
final readonly class ConsentBanner
{
    public function __construct(
        private ConsentGate $gate,
        private string $defaultLocale = 'de',
    ) {}

    /**
     * Pending material changes the subject has not yet accepted, currently within their
     * grace window.
     *
     * @return list<array{key: string, version: string, title: string, announce_from: ?string, enforce_from: ?string, days_left: int}>
     */
    public function pendingFor(Model $subject, ?string $locale = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $locale ??= $this->defaultLocale;

        $upcoming = LegalDocument::query()
            ->select(['key', 'version', 'title', 'major_version', 'announce_from', 'enforce_from'])
            ->where('locale', $locale)
            ->where('is_active', true)
            ->where('requires_explicit_optin', false)
            ->where('requires_reconsent', true)
            ->whereNotNull('announce_from')
            ->where('announce_from', '<=', $now)
            ->whereNotNull('enforce_from')
            ->where('enforce_from', '>', $now)
            ->get();

        if ($upcoming->isEmpty()) {
            return [];
        }

        $accepted = $this->gate->highestAcceptedMajors($subject, $locale);
        $pending = [];

        foreach ($upcoming as $document) {
            if (($accepted[$document->key] ?? 0) >= $document->major_version) {
                continue; // subject already accepted this version
            }

            $enforce = $document->enforce_from;

            $pending[] = [
                'key' => $document->key,
                'version' => $document->version,
                'title' => $document->title,
                'announce_from' => $document->announce_from?->toIso8601String(),
                'enforce_from' => $enforce?->toIso8601String(),
                'days_left' => $enforce instanceof CarbonImmutable ? max(0, (int) $now->diffInDays($enforce)) : 0,
            ];
        }

        return $pending;
    }

    /**
     * Info-only changes (NoticeMode::InfoPush) currently in their notice window — a
     * dismissible "this was updated, no action required" banner, distinct from the
     * countdown grace banner. Info-only applies to everyone equally, so it is not
     * per-subject: it takes effect regardless of the subject's reaction.
     *
     * @return list<array{key: string, version: string, title: string, effective_from: ?string, offers_termination: bool}>
     */
    public function informationalFor(?string $locale = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $locale ??= $this->defaultLocale;

        $active = LegalDocument::query()
            ->select(['key', 'version', 'title', 'enforce_from', 'offers_termination'])
            ->where('locale', $locale)
            ->where('is_active', true)
            ->where('notice_mode', NoticeMode::InfoPush->value)
            ->whereNotNull('announce_from')
            ->where('announce_from', '<=', $now)
            ->whereNotNull('enforce_from')
            ->where('enforce_from', '>', $now)
            ->get();

        $informational = [];

        foreach ($active as $document) {
            $informational[] = [
                'key' => $document->key,
                'version' => $document->version,
                'title' => $document->title,
                'effective_from' => $document->enforce_from?->toIso8601String(),
                'offers_termination' => (bool) $document->offers_termination,
            ];
        }

        return $informational;
    }

    /**
     * Deemed-consent (Zustimmungsfiktion) changes still within their objection window that
     * this subject holds an older major of and has not yet objected to or terminated — the
     * banner that surfaces the objection / free-termination options while the subject can
     * still exercise them (§ 308 Nr. 5 lit. a BGB). `days_left` counts down to the objection
     * deadline, not the effective date.
     *
     * @return list<array{key: string, version: string, title: string, objection_deadline: ?string, enforce_from: ?string, days_left: int}>
     */
    public function deemedFor(Model $subject, ?string $locale = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $locale ??= $this->defaultLocale;

        $upcoming = LegalDocument::query()
            ->select(['key', 'version', 'title', 'major_version', 'objection_deadline', 'enforce_from'])
            ->where('locale', $locale)
            ->where('is_active', true)
            ->where('notice_mode', NoticeMode::DeemedConsent->value)
            ->whereNotNull('announce_from')
            ->where('announce_from', '<=', $now)
            ->whereNotNull('objection_deadline')
            ->where('objection_deadline', '>', $now)
            ->get();

        if ($upcoming->isEmpty()) {
            return [];
        }

        $accepted = $this->gate->highestAcceptedMajors($subject, $locale);
        $deemed = [];

        foreach ($upcoming as $document) {
            if (($accepted[$document->key] ?? 0) >= $document->major_version) {
                continue; // subject already holds this version
            }

            $latest = $this->gate->latestActionFor($subject, $document->key, $locale);

            if ($latest instanceof LegalConsent && ($latest->action === ConsentAction::Objected || $latest->action === ConsentAction::Terminated)) {
                continue; // the subject already objected or terminated
            }

            $deadline = $document->objection_deadline;

            $deemed[] = [
                'key' => $document->key,
                'version' => $document->version,
                'title' => $document->title,
                'objection_deadline' => $deadline?->toIso8601String(),
                'enforce_from' => $document->enforce_from?->toIso8601String(),
                'days_left' => $deadline instanceof CarbonImmutable ? max(0, (int) $now->diffInDays($deadline)) : 0,
            ];
        }

        return $deemed;
    }

    /**
     * All three banner datasets for a subject in one call — the re-consent countdown, the
     * info-only heads-up, and the deemed-consent objection window — so a single banner view
     * can render whichever apply.
     *
     * @return array{reconsent: list<array<string, mixed>>, informational: list<array<string, mixed>>, deemed: list<array<string, mixed>>}
     */
    public function forSubject(Model $subject, ?string $locale = null, ?CarbonImmutable $now = null): array
    {
        return [
            'reconsent' => $this->pendingFor($subject, $locale, $now),
            'informational' => $this->informationalFor($locale, $now),
            'deemed' => $this->deemedFor($subject, $locale, $now),
        ];
    }
}
