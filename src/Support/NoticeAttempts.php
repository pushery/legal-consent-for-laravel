<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Each change notice on its way, or failed, per subject and version.
 *
 * The notice sweep reads this to decide whom a version still owes a notice: not a subject whose
 * notice is still in the queue, and not one whose notice has failed `notifications.max_attempts`
 * times. The failure listener writes it, the delivery listener deletes the row once a notice went
 * out, and `legal-consent:renotify` forgets a version's rows so that every subject is tried again.
 *
 * An installation that has not run migration 000032 yet has no such table, and everything here then
 * does nothing: the sweep behaves as it did before the table existed.
 */
final class NoticeAttempts
{
    public const string TABLE = 'legal_notice_attempts';

    public static function tracked(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /**
     * How often one subject's notice is attempted before the sweep stops and reports it instead.
     */
    public static function maxAttempts(): int
    {
        $configured = IntegerSetting::from(config('legal-consent.notifications.max_attempts'));

        return $configured !== null && $configured >= 1 ? $configured : 3;
    }

    /**
     * The moment before which a queued notice that was neither delivered nor failed counts as lost.
     */
    public static function staleBefore(): CarbonImmutable
    {
        $configured = IntegerSetting::from(config('legal-consent.notifications.requeue_after_minutes'));

        return CarbonImmutable::now()->subMinutes($configured !== null && $configured >= 1 ? $configured : 1440);
    }

    /**
     * Records that these subjects' notices for the version are on their way.
     *
     * A notice queued earlier that came back neither delivered nor failed within the window was lost
     * on the way, and that counts as an attempt: otherwise a queue that never runs would have the
     * same subjects queued again after every window, without end.
     *
     * @param  Collection<int, Model>  $subjects
     */
    public static function queued(LegalDocument $version, Collection $subjects): void
    {
        if ($subjects->isEmpty() || ! self::tracked()) {
            return;
        }

        $now = CarbonImmutable::now();
        $stale = self::staleBefore();

        foreach ($subjects->groupBy(static fn (Model $subject): string => (string) $subject->getMorphClass()) as $type => $group) {
            DB::table(self::TABLE)
                ->where('document_id', $version->getKey())
                ->where('subject_type', (string) $type)
                ->whereIn('subject_id', $group->map(SubjectKey::for(...))->all())
                ->whereNull('failed_at')
                ->where('queued_at', '<=', $stale)
                ->increment('failures');
        }

        DB::table(self::TABLE)->upsert(
            $subjects->map(static fn (Model $subject): array => [
                'document_id' => $version->getKey(),
                'subject_type' => (string) $subject->getMorphClass(),
                'subject_id' => SubjectKey::for($subject),
                'queued_at' => $now,
                'failures' => 0,
                'failed_at' => null,
            ])->all(),
            ['document_id', 'subject_type', 'subject_id'],
            // An existing row keeps its count of failures and is on its way again.
            ['queued_at', 'failed_at'],
        );
    }

    /**
     * Records one failed attempt for this subject and version, and returns how many there have been.
     */
    public static function failed(LegalDocument $version, Model $subject): int
    {
        if (! self::tracked()) {
            return 0;
        }

        $key = [
            'document_id' => $version->getKey(),
            'subject_type' => (string) $subject->getMorphClass(),
            'subject_id' => SubjectKey::for($subject),
        ];
        $now = CarbonImmutable::now();

        if (DB::table(self::TABLE)->where($key)->increment('failures', 1, ['failed_at' => $now]) === 0) {
            // A notice sent outside the sweep, by a consumer or a re-run, has no row yet.
            try {
                DB::table(self::TABLE)->insert([...$key, 'queued_at' => $now, 'failures' => 1, 'failed_at' => $now]);
            } catch (UniqueConstraintViolationException) {
                DB::table(self::TABLE)->where($key)->increment('failures', 1, ['failed_at' => $now]);
            }
        }

        $failures = DB::table(self::TABLE)->where($key)->value('failures');

        return is_numeric($failures) ? (int) $failures : 0;
    }

    /**
     * Forgets the attempts once the notice went out: the proof row answers from here on.
     */
    public static function delivered(LegalDocument $version, Model $subject): void
    {
        if (! self::tracked()) {
            return;
        }

        $subjectId = SubjectKey::for($subject);

        // A subject without a key has no attempts to forget, and `subject_id = null` names nobody.
        if ($subjectId === null) {
            return;
        }

        DB::table(self::TABLE)
            ->where('document_id', $version->getKey())
            ->where('subject_type', (string) $subject->getMorphClass())
            ->where('subject_id', $subjectId)
            ->delete();
    }

    /**
     * Forgets every attempt recorded for the version, and returns how many rows that was.
     */
    public static function forgetVersion(LegalDocument $version): int
    {
        return self::tracked() ? DB::table(self::TABLE)->where('document_id', $version->getKey())->delete() : 0;
    }

    /**
     * Forgets every attempt recorded for the subject.
     */
    public static function forgetSubject(string $subjectType, string $subjectId): void
    {
        if (self::tracked()) {
            DB::table(self::TABLE)->where('subject_type', $subjectType)->where('subject_id', $subjectId)->delete();
        }
    }

    /**
     * Narrows a query over `legal_consents` to the subjects the version still owes a notice attempt:
     * none whose notice is on its way, and none whose notice failed too often.
     */
    public static function whereStillOwed(QueryBuilder $query, LegalDocument $version): QueryBuilder
    {
        if (! self::tracked()) {
            return $query;
        }

        return $query->whereNotExists(
            fn (QueryBuilder $attempt): QueryBuilder => $attempt->from(self::TABLE)
                ->whereColumn(self::TABLE.'.subject_type', 'legal_consents.subject_type')
                ->whereColumn(self::TABLE.'.subject_id', 'legal_consents.subject_id')
                ->where(self::TABLE.'.document_id', $version->getKey())
                ->where(fn (QueryBuilder $blocking): QueryBuilder => $blocking
                    ->where(self::TABLE.'.failures', '>=', self::maxAttempts())
                    ->orWhere(fn (QueryBuilder $onItsWay): QueryBuilder => $onItsWay
                        ->whereNull(self::TABLE.'.failed_at')
                        ->where(self::TABLE.'.queued_at', '>', self::staleBefore()))),
        );
    }

    /**
     * Widens a query over the versions to those with a notice to try again: one that failed and may
     * be retried, or one that was lost on its way.
     *
     * @template TModel of LegalDocument
     *
     * @param  EloquentBuilder<TModel>  $query
     * @return EloquentBuilder<TModel>
     */
    public static function orWhereRetryDue(EloquentBuilder $query): EloquentBuilder
    {
        if (! self::tracked()) {
            return $query;
        }

        $model = $query->getModel();
        $documentKey = $model->getTable().'.'.$model->getKeyName();

        return $query->orWhereExists(
            fn (QueryBuilder $attempt): QueryBuilder => $attempt->from(self::TABLE)
                ->whereColumn(self::TABLE.'.document_id', $documentKey)
                ->where(self::TABLE.'.failures', '<', self::maxAttempts())
                ->where(fn (QueryBuilder $retry): QueryBuilder => $retry
                    ->whereNotNull(self::TABLE.'.failed_at')
                    ->orWhere(self::TABLE.'.queued_at', '<=', self::staleBefore())),
        );
    }

    /**
     * The subjects whose notice failed `notifications.max_attempts` times, counted per version id.
     *
     * @return array<int, int>
     */
    public static function unreachable(): array
    {
        if (! self::tracked()) {
            return [];
        }

        $counts = [];

        foreach (DB::table(self::TABLE)
            ->select('document_id', DB::raw('count(*) as subjects'))
            ->where('failures', '>=', self::maxAttempts())
            ->groupBy('document_id')
            ->get() as $row) {
            // A driver may hand an aggregate back as a string, so each value is read as a number.
            $documentId = $row->document_id ?? null;
            $subjects = $row->subjects ?? null;

            if (is_numeric($documentId) && is_numeric($subjects)) {
                $counts[(int) $documentId] = (int) $subjects;
            }
        }

        return $counts;
    }
}
