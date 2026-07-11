<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OPTIONAL, publish-only migration path from a "v1"-style schema (two nullable
 * `users.privacy_accepted_at` / `users.terms_accepted_at` timestamps) into the
 * ledger. Each existing timestamp becomes one `import` entry that PRESERVES the
 * original acceptance time; because v1 never archived the shown wording or a hash,
 * the entry carries a sentinel hash and a "(wording not archived)" snapshot — a
 * weak but honest proof, which is exactly what the method `import` signals.
 *
 * Not auto-loaded. Publish + run only if you are migrating from that schema:
 *   php artisan vendor:publish --tag=legal-consent-backfill
 * A no-op when neither legacy column exists, and idempotent (skips if already run).
 *
 * Adapt the column/type mapping to your own legacy schema before running.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hasTerms = Schema::hasTable('users') && Schema::hasColumn('users', 'terms_accepted_at');
        $hasPrivacy = Schema::hasTable('users') && Schema::hasColumn('users', 'privacy_accepted_at');

        if (! $hasTerms && ! $hasPrivacy) {
            return;
        }

        // Idempotent: if a previous run already backfilled, do nothing.
        if (DB::table('legal_consents')->where('source', 'v1_backfill')->exists()) {
            return;
        }

        DB::disableQueryLog();

        $userModel = (string) config('auth.providers.users.model', 'App\\Models\\User');
        $subjectType = class_exists($userModel) ? (new $userModel)->getMorphClass() : $userModel;
        $locale = (string) config('legal-consent.default_locale', 'de');
        $now = now();

        $columns = array_values(array_filter([
            'id',
            $hasTerms ? 'terms_accepted_at' : null,
            $hasPrivacy ? 'privacy_accepted_at' : null,
        ]));

        DB::table('users')
            ->select($columns)
            ->orderBy('id')
            ->chunkById(1000, function ($users) use ($hasTerms, $hasPrivacy, $subjectType, $locale, $now): void {
                $rows = [];

                foreach ($users as $user) {
                    if ($hasTerms && $user->terms_accepted_at !== null) {
                        $rows[] = $this->row($subjectType, (int) $user->id, 'terms', 'contract_terms', $user->terms_accepted_at, $locale, $now);
                    }
                    if ($hasPrivacy && $user->privacy_accepted_at !== null) {
                        $rows[] = $this->row($subjectType, (int) $user->id, 'privacy', 'privacy_notice', $user->privacy_accepted_at, $locale, $now);
                    }
                }

                if ($rows !== []) {
                    DB::table('legal_consents')->insert($rows);
                }

                unset($rows);
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('legal_consents')) {
            DB::table('legal_consents')->where('source', 'v1_backfill')->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $subjectType, int $subjectId, string $key, string $type, mixed $acceptedAt, string $locale, mixed $now): array
    {
        return [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_token' => null,
            'document_id' => null,
            'document_key' => $key,
            'document_type' => $type,
            'document_version' => '1.0.0',
            'document_major_version' => 1,
            'content_hash' => str_pad('UNKNOWN_HASH', 64, '0'),   // sentinel: v1 archived no hash
            'locale' => $locale,
            'ui_wording_snapshot' => '(v1: Wortlaut nicht archiviert)',
            'action' => 'acknowledged',
            'method' => 'import',
            'source' => 'v1_backfill',
            'ip_address' => null,
            'user_agent' => null,
            'request_id' => null,
            'prev_record_hash' => null,
            'accepted_at' => $acceptedAt,   // preserve the ORIGINAL acceptance time
            'created_at' => $now,
        ];
    }
};
