<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * One chunk's notices as the sweep recorded them on their way, with the rows that record replaced.
 *
 * {@see NoticeAttempts::queued()} writes the record for a whole chunk before the first notice of it
 * is handed over. A run that stops part of the way must not leave the rest standing as on their way:
 * the sweep would pass those subjects over until the window ends, then count an attempt nobody
 * made, and after `notifications.max_attempts` such stops report a subject nobody ever wrote to as
 * unreachable. {@see putBack()} returns their rows to what they were before the run.
 */
final readonly class QueuedNotices
{
    /**
     * @param  array<string, array{queued_at: mixed, failures: mixed, failed_at: mixed}|null>  $before  each queued subject's row before the run, keyed by {@see SubjectKey::pair()}, null where it had none
     */
    public function __construct(
        private LegalDocument $version,
        private CarbonImmutable $at,
        private array $before,
    ) {}

    /**
     * Puts the rows of these subjects back as they stood before the run recorded them.
     *
     * Only a row nothing has touched since: a delivered notice deleted its row and a failed one
     * stamped `failed_at`, and either is an attempt that happened and stays recorded. A subject whose
     * notice does not go out by mail was never recorded, so it has no row of this run to put back.
     *
     * @param  Collection<int, Model>  $subjects
     */
    public function putBack(Collection $subjects): void
    {
        $recorded = $subjects->filter(fn (Model $subject): bool => array_key_exists((string) SubjectKey::pairFor($subject), $this->before));

        foreach ($recorded as $subject) {
            $previous = $this->before[(string) SubjectKey::pairFor($subject)];

            $row = DB::table(NoticeAttempts::TABLE)
                ->where('document_id', $this->version->getKey())
                ->where('subject_type', (string) $subject->getMorphClass())
                ->where('subject_id', (string) SubjectKey::for($subject))
                ->where('queued_at', $this->at)
                ->whereNull('failed_at');

            if ($previous === null) {
                $row->delete();
            } else {
                $row->update($previous);
            }
        }
    }
}
