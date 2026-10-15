<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Pushery\LegalConsent\Exceptions\InvalidRetentionPeriod;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalNotice;

/**
 * The instants before which the records of each document are past their retention: the cutoff of
 * `legal-consent.retention_after_end`, and for a document `legal-consent.retention_by_document`
 * gives a period of its own, the cutoff of that period.
 *
 * Some acceptances have to stay provable for longer than the default, such as an agreement that
 * self-billed invoices rest on, which are kept for eight years (§ 14b UStG). Such a document keeps
 * its superseded and orphaned records until its own period ends, and the longer period still
 * ends, which is what storage limitation asks of it (Art. 5(1)(e) GDPR).
 */
final readonly class RetentionCutoffs
{
    /**
     * @param  array<string, array{retention: string, cutoff: CarbonImmutable}>  $documents
     */
    private function __construct(
        public string $retention,
        public CarbonImmutable $cutoff,
        public array $documents,
    ) {}

    /**
     * Every period read from the configuration, each checked before anything is deleted.
     *
     * @throws InvalidRetentionPeriod when a period gives no cutoff at least a day before now
     */
    public static function configured(mixed $default, mixed $byDocument, CarbonImmutable $now): self
    {
        $retention = RetentionPeriod::configured($default);
        $documents = [];

        foreach (self::entries($byDocument) as $document => $value) {
            $period = self::period($document, $value);

            $documents[$document] = [
                'retention' => $period,
                'cutoff' => RetentionPeriod::cutoff($period, $now, self::key($document)),
            ];
        }

        return new self($retention, RetentionPeriod::cutoff($retention, $now), $documents);
    }

    /**
     * Why the period of a document's own gives no cutoff, keyed by document, or nothing when every
     * one gives one.
     *
     * @return array<string, string>
     */
    public static function problems(mixed $byDocument, CarbonImmutable $now): array
    {
        $problems = [];

        foreach (self::entries($byDocument) as $document => $value) {
            try {
                RetentionPeriod::cutoff(self::period($document, $value), $now, self::key($document));
            } catch (InvalidRetentionPeriod $refusal) {
                $problems[$document] = $refusal->getMessage();
            }
        }

        return $problems;
    }

    /**
     * Narrows `$query` to the records past the retention of their document.
     *
     * Bound to the two ledgers, whose records carry the `document_key` the narrowing reads.
     *
     * @template TModel of LegalConsent|LegalNotice
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function expired(Builder $query, string $timestamp): Builder
    {
        return $query->where(function (Builder $expired) use ($timestamp): void {
            foreach ($this->documents as $document => $own) {
                $expired->orWhere(fn (Builder $record): Builder => $record->where('document_key', $document)->where($timestamp, '<', $own['cutoff']));
            }

            $expired->orWhere(fn (Builder $record): Builder => $record->whereNotIn('document_key', array_keys($this->documents))->where($timestamp, '<', $this->cutoff));
        });
    }

    /** The periods for the sweep's report: `3 years`, or `3 years, or older than 10 years for partner_terms`. */
    public function described(): string
    {
        $own = [];

        foreach ($this->documents as $document => $entry) {
            $own[] = "{$entry['retention']} for {$document}";
        }

        return $own === [] ? $this->retention : "{$this->retention}, or older than ".implode(', ', $own);
    }

    /**
     * The documents given a period of their own, with the value as configured. An empty value is no
     * period of its own, so that document keeps the default.
     *
     * @return array<string, mixed>
     */
    private static function entries(mixed $byDocument): array
    {
        $entries = [];

        foreach (is_array($byDocument) ? $byDocument : [] as $document => $value) {
            if (is_string($document) && $document !== '' && $value !== null && $value !== '') {
                $entries[$document] = $value;
            }
        }

        return $entries;
    }

    /**
     * A period of a document's own, refused when it is not a string. Read as the default instead, a
     * mistyped period would delete that document's proof sooner than the setting asked for.
     *
     * @throws InvalidRetentionPeriod for a value that is not a string
     */
    private static function period(string $document, mixed $value): string
    {
        if (! is_string($value)) {
            throw InvalidRetentionPeriod::unreadable((string) json_encode($value), self::key($document));
        }

        return $value;
    }

    private static function key(string $document): string
    {
        return "legal-consent.retention_by_document.{$document}";
    }
}
