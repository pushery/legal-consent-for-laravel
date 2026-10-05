<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
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
 * A no-op when neither legacy column exists. All or nothing: a run that fails leaves no row behind
 * and can simply be run again, and a run that finished is skipped.
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

        // A backfilled row means a finished import, because the import below is one transaction.
        if (DB::table('legal_consents')->where('source', 'v1_backfill')->exists()) {
            return;
        }

        DB::disableQueryLog();

        // Both values come out of config as mixed, and this migration runs against a
        // stranger's application: a host app that has published its own auth config with a
        // non-string model, or dropped `default_locale`, must not produce a ledger row whose
        // subject_type or locale is the string "Array". Fall back instead of coercing.
        $configuredModel = config('auth.providers.users.model');
        $userModel = is_string($configuredModel) && $configuredModel !== ''
            ? $configuredModel
            : 'App\\Models\\User';

        // is_a(..., allow_string: true) also answers class_exists() — and it answers the
        // question that actually matters here, because getMorphClass() only exists on an
        // Eloquent model. A class that exists but is not one now falls back to its own name
        // rather than fataling mid-backfill.
        $subjectType = is_a($userModel, Model::class, true)
            ? (new $userModel)->getMorphClass()
            : $userModel;

        $configuredLocale = config('legal-consent.default_locale');
        $locale = is_string($configuredLocale) && $configuredLocale !== '' ? $configuredLocale : 'de';

        $now = now();

        $columns = array_values(array_filter([
            'id',
            $hasTerms ? 'terms_accepted_at' : null,
            $hasPrivacy ? 'privacy_accepted_at' : null,
        ]));

        // One transaction for the whole import, so a run that fails leaves no row behind and the
        // check above can read a backfilled row as a finished import. Laravel wraps a migration in a
        // transaction only where the schema grammar supports one, which PostgreSQL does and MySQL
        // and SQLite do not.
        DB::transaction(fn () => DB::table('users')
            ->select($columns)
            ->orderBy('id')
            ->chunkById(1000, /** @param Collection<int, stdClass> $users */ function (Collection $users) use ($hasTerms, $hasPrivacy, $subjectType, $locale, $now): void {
                $rows = [];

                foreach ($users as $user) {
                    // A raw builder row is a stdClass, so every property is mixed. The id is
                    // the one value that must not be guessed at: folding an unexpected shape
                    // into a key would attach a stranger's acceptance to it. Skip the row
                    // instead — a backfill that silently mislabels a subject is worse than one
                    // that leaves a row behind for the operator to see.
                    //
                    // A NON-NUMERIC KEY IS NOW LEGITIMATE, and this check used to reject it.
                    // While `subject_id` was an integer column, `ctype_digit()` was the only
                    // thing standing between a UUID and PHP quietly casting it to 0 — so the
                    // digit test WAS the safety. Since 0.18 the column holds a 64-character
                    // string, a UUID or ULID key is an ordinary subject, and keeping that test
                    // would silently drop exactly the installations the widening was for: the
                    // operator would see a green backfill and an empty ledger.
                    //
                    // What is refused instead is the same class the proof chain refuses: a value
                    // with no lossless string form. `true` casts to '1' and an array to 'Array',
                    // both keys a real subject can hold, so admitting them would put two subjects
                    // on one key. `false` casts to '', which this check refuses as an empty key
                    // anyway, and an object without __toString() does not cast at all.
                    $id = $user->id;

                    if (! is_int($id) && (! is_string($id) || $id === '')) {
                        continue;
                    }

                    if ($hasTerms && $user->terms_accepted_at !== null) {
                        $rows[] = $this->row($subjectType, $id, 'terms', 'contract_terms', $user->terms_accepted_at, $locale, $now);
                    }
                    if ($hasPrivacy && $user->privacy_accepted_at !== null) {
                        $rows[] = $this->row($subjectType, $id, 'privacy', 'privacy_notice', $user->privacy_accepted_at, $locale, $now);
                    }
                }

                if ($rows !== []) {
                    DB::table('legal_consents')->insert($rows);
                }

                unset($rows);
            }));
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
    private function row(string $subjectType, int|string $subjectId, string $key, string $type, mixed $acceptedAt, string $locale, mixed $now): array
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
