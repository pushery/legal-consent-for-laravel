<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Pushery\LegalConsent\Support\SubjectKey;

/**
 * A `morphMany` onto a varchar key column that still works for a subject keyed by an integer or a
 * native `uuid` on PostgreSQL.
 *
 * `subject_id` is a varchar since the column was widened for UUID and ULID subjects. That is right
 * for the ledger, and it leaves the two relation forms that compare the column inside the SQL text
 * broken on PostgreSQL, which has no `varchar = integer`, `bigint = varchar` or `uuid = varchar`
 * operator. With a `bigint` user key and a plain `morphMany`, the lazy `$user->legalConsents()`
 * works, `User::with('legalConsents')` fails with
 * `operator does not exist: character varying = integer`, and `User::whereHas('legalConsents')`
 * with `operator does not exist: bigint = character varying`.
 *
 * MySQL does not fail, and that is its own defect: it compares a varchar column with a number by
 * turning both sides into a DOUBLE. The `(subject_type, subject_id)` index is then good for the
 * type alone, so every read scans every consent of that subject type, and past 2^53 two
 * neighboring keys compare equal and one subject is handed the other's consents. So the parent
 * key is bound as a string on every path, the form {@see SubjectKey} gives the package's own
 * queries, and the existence query casts it on MySQL as it does on PostgreSQL.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 */
final class StringKeyedMorphMany extends MorphMany
{
    /**
     * The parent key as `subject_id` holds it: a string, so that the lazy constraint and a create
     * through the relation bind the value the column compares as text.
     */
    public function getParentKey(): ?string
    {
        return SubjectKey::from(parent::getParentKey());
    }

    /**
     * The eager constraint with every parent key bound as a string, for the reason getParentKey()
     * gives, and the morph type as MorphOneOrMany adds it.
     *
     * Through `whereIn`, never Eloquent's `whereIntegerInRaw`, which it picks whenever the local key
     * is the parent's own integer primary key and which writes the keys INTO the statement unquoted:
     * `where "legal_consents"."subject_id" in (1, 2)`. PostgreSQL types a bare `1` as `integer` and
     * refuses the comparison. A bound parameter is sent without a declared type, so the server
     * infers it from the column it meets.
     *
     * @param  array<int, TDeclaringModel>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        $keys = [];

        foreach ($this->getKeys($models, $this->localKey) as $key) {
            $key = SubjectKey::from($key);

            if ($key !== null) {
                $keys[] = $key;
            }
        }

        $this->whereInEager('whereIn', $this->foreignKey, $keys, $this->getRelationQuery());

        $this->getRelationQuery()->where($this->morphType, $this->morphClass);
    }

    /**
     * The existence query behind `has()`, `whereHas()`, `whereDoesntHave()` and `withCount()`,
     * with the parent key compared as text on PostgreSQL and MySQL.
     *
     * That query compares two COLUMNS, `"users"."id" = "legal_consents"."subject_id"`, so there is no
     * parameter for the server to infer a type from, and binding cannot help. The cast sits on the
     * PARENT side on purpose: the outer key is one value per outer row, while a cast on
     * `subject_id` would hide the column from the `(subject_type, subject_id)` index every lookup
     * relies on.
     *
     * On PostgreSQL the cast is `AS TEXT`, on MySQL `AS CHAR`: MySQL would otherwise compare the
     * two columns as DOUBLEs, for the reason in the class docblock. SQLite compares a text column
     * with an integer one exactly, so the framework's comparison stays what it was there.
     *
     * The columns are applied AFTER the framework has built its query rather than handed to it, and
     * the result is the same: every existence query starts with `select($columns)`, and `select()`
     * replaces the column list. Handing them through would pass a value typed as a model property
     * list, which `count(*)` from `withCount()` is not.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     *
     * @phpstan-param  mixed  $columns
     *
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $base = $query->getQuery();
        $grammar = $base->getGrammar();

        $textType = match (true) {
            $grammar instanceof PostgresGrammar => 'text',
            $grammar instanceof MySqlGrammar => 'char',
            default => null,
        };

        if ($textType === null || $base->from === $parentQuery->getQuery()->from) {
            return parent::getRelationExistenceQuery($query, $parentQuery)->select($columns);
        }

        return $query->select($columns)
            ->whereColumn(
                $base->raw('cast('.$grammar->wrap($this->getQualifiedParentKeyName()).' as '.$textType.')'),
                '=',
                $this->getExistenceCompareKey(),
            )
            ->where($query->qualifyColumn($this->getMorphType()), $this->morphClass);
    }
}
