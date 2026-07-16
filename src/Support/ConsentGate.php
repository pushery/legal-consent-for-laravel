<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Decides which mandatory documents a subject still owes acceptance for.
 *
 * The comparison is over `major_version` ONLY, never the content hash: an editorial
 * fix (same major) never forces a re-consent, while a material change (a new major,
 * published with requires_reconsent) does — but only once its enforcement window has
 * opened (EDPB 05/2020 Rz. 110; the grace period comes from enforce_from).
 *
 * Only an ACTIVE re-consent (NoticeMode::ActiveReconsent) hard-blocks. An info-only change
 * takes effect regardless and a deemed-consent change binds by silence (via the objection
 * window, not an access block) — neither gates. This is what keeps a privacy notice, which
 * is info-only, from ever blocking access (WP260 rev.01 Rz. 30-31): forcing acknowledgement
 * to regain access would be unlawful pressure.
 */
final class ConsentGate
{
    /**
     * The active, enforceable, mandatory documents whose current major version the
     * subject has not yet accepted.
     *
     * @return Collection<int, LegalDocument>
     */
    public function outstandingFor(Model $subject, string $locale, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();

        $enforceable = LegalDocument::query()
            ->select(['id', 'key', 'locale', 'type', 'major_version', 'version', 'title', 'ui_wording', 'content_hash', 'announce_from', 'enforce_from'])
            ->where('locale', $locale)
            ->where('is_active', true)
            ->where('requires_explicit_optin', false)
            ->where('notice_mode', NoticeMode::ActiveReconsent->value) // only an active re-consent gates
            ->whereNotNull('enforce_from')
            ->where('enforce_from', '<=', $now)
            ->get();

        if ($enforceable->isEmpty()) {
            return $enforceable;
        }

        $highestAccepted = $this->highestAcceptedMajors($subject, $locale);

        return $enforceable
            ->filter(fn (LegalDocument $document): bool => ($highestAccepted[$document->key] ?? 0) < $document->major_version)
            ->values();
    }

    /**
     * The subject's highest accepted major version per document key IN A GIVEN LOCALE,
     * considering only the "accepting" actions (granted / acknowledged / re-accepted /
     * parental). Locale-scoped: acceptance of one locale's text never satisfies another's.
     *
     * @return array<string, int>
     */
    public function highestAcceptedMajors(Model $subject, string $locale): array
    {
        $accepting = array_map(static fn (ConsentAction $action): string => $action->value, ConsentAction::accepting());

        $tenant = app(TenantContext::class);

        $rows = DB::table('legal_consents')
            ->select('document_key', DB::raw('MAX(document_major_version) as max_major'))
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('locale', $locale)
            ->when($tenant->enabled(), fn (QueryBuilder $query): QueryBuilder => $query->where('tenant_id', $tenant->current()))
            ->whereIn('action', $accepting)
            ->groupBy('document_key')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $key = $row->document_key;
            $major = $row->max_major;

            if (is_string($key)) {
                $map[$key] = is_numeric($major) ? (int) $major : 0;
            }
        }

        return $map;
    }

    /**
     * The subject's most recent ledger entry for a (document_key, locale) — the source of
     * truth for whether they CURRENTLY hold it, since a monotonic max cannot see a later
     * withdrawal (Art. 7(3)).
     */
    public function latestActionFor(Model $subject, string $documentKey, string $locale): ?LegalConsent
    {
        return LegalConsent::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('document_key', $documentKey)
            ->where('locale', $locale)
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->first();
    }
}
