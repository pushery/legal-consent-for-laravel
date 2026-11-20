<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
     * @param  array<array-key, array{retention: string, cutoff: CarbonImmutable}>  $documents
     */
    private function __construct(
        public string $retention,
        public CarbonImmutable $cutoff,
        public array $documents,
    ) {}

    /**
     * Every period read from the configuration, each checked before anything is deleted.
     *
     * @param  mixed  $default  a value of the package's config, which a host may have set to any type
     * @param  mixed  $byDocument  a value of the package's config, which a host may have set to any type
     *
     * @throws InvalidRetentionPeriod when a period gives no cutoff at least a day before now
     */
    public static function configured(mixed $default, mixed $byDocument, CarbonImmutable $now): self
    {
        $retention = RetentionPeriod::configured($default);
        $documents = [];

        foreach (self::entries($byDocument) as $key => $value) {
            $document = (string) $key;
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
     * @param  mixed  $byDocument  a value of the package's config, which a host may have set to any type
     * @return array<array-key, string>
     */
    public static function problems(mixed $byDocument, CarbonImmutable $now): array
    {
        $problems = [];

        foreach (self::entries($byDocument) as $key => $value) {
            $document = (string) $key;

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
                $expired->orWhere(fn (Builder $record): Builder => $record->where('document_key', (string) $document)->where($timestamp, '<', $own['cutoff']));
            }

            $expired->orWhere(fn (Builder $record): Builder => $record->whereNotIn('document_key', $this->documentKeys())->where($timestamp, '<', $this->cutoff));
        });
    }

    /**
     * The same narrowing on a query that names its own columns, such as a correlated subquery over an
     * alias of the ledger: `newer.accepted_at` past the cutoff of the document `newer.document_key` names.
     *
     * The sweep asks it of the row that superseded another, because a superseded record's retention
     * runs from the moment it stopped being the current standing, not from its own acceptance.
     */
    public function expiredOn(QueryBuilder $query, string $timestamp, string $documentKey): QueryBuilder
    {
        return $query->where(function (QueryBuilder $expired) use ($timestamp, $documentKey): void {
            foreach ($this->documents as $document => $own) {
                $expired->orWhere(fn (QueryBuilder $record): QueryBuilder => $record->where($documentKey, (string) $document)->where($timestamp, '<', $own['cutoff']));
            }

            $expired->orWhere(fn (QueryBuilder $record): QueryBuilder => $record->whereNotIn($documentKey, $this->documentKeys())->where($timestamp, '<', $this->cutoff));
        });
    }

    /**
     * The keys of the documents with a period of their own, as the strings the ledgers hold. A key made of
     * digits is an int key of the array, and bound as an int it would compare a text column with a number.
     *
     * @return list<string>
     */
    private function documentKeys(): array
    {
        return array_map(strval(...), array_keys($this->documents));
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
     * period of its own, so that document keeps the default. A document key made of digits is an int
     * key, the way PHP stores it, and a document all the same.
     *
     * @param  mixed  $byDocument  a value of the package's config, which a host may have set to any type
     * @return array<array-key, mixed>
     */
    private static function entries(mixed $byDocument): array
    {
        $entries = [];

        foreach (is_array($byDocument) ? $byDocument : [] as $document => $value) {
            if ($document !== '' && $value !== null && $value !== '') {
                $entries[$document] = $value;
            }
        }

        return $entries;
    }

    /**
     * A period of a document's own, refused when it is not a string. Read as the default instead, a
     * mistyped period would delete that document's proof sooner than the setting asked for.
     *
     * @param  mixed  $value  a value of the package's config, which a host may have set to any type
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
