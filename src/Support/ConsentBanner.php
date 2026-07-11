<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
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
}
