<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * A `morphMany` onto a varchar key column that still works for a subject keyed by an integer or a
 * native `uuid` on PostgreSQL.
 *
 * `subject_id` is a varchar since the column was widened for UUID and ULID subjects. That is right
 * for the ledger, and it left the two relation forms that compare the column inside the SQL text
 * broken on PostgreSQL, which has no `varchar = integer`, `bigint = varchar` or `uuid = varchar`
 * operator. Measured in a consumer on 0.26.1 with a `bigint` user key: the lazy
 * `$user->legalConsents()` worked, `User::with('legalConsents')` failed with
 * `operator does not exist: character varying = integer`, and `User::whereHas('legalConsents')`
 * with `operator does not exist: bigint = character varying`. MySQL and SQLite convert silently,
 * which is how it stayed green everywhere else.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 */
final class StringKeyedMorphMany extends MorphMany
{
    /**
     * Force the bound `whereIn` over Eloquent's `whereIntegerInRaw` for the eager constraint.
     *
     * `Relation::whereInMethod()` picks `whereIntegerInRaw` whenever the local key is the parent's
     * own integer primary key, and that path writes the keys INTO the statement unquoted:
     * `where "legal_consents"."subject_id" in (1, 2)`. PostgreSQL types a bare `1` as `integer` and
     * refuses the comparison. A bound parameter is sent without a declared type, so the server
     * infers it from the column it meets, which is also why the lazy path never broke: it has always
     * bound `where "subject_id" = ?`.
     *
     * @param  string  $key
     */
    protected function whereInMethod(Model $model, mixed $key): string
    {
        return 'whereIn';
    }

    /**
     * The existence query behind `has()`, `whereHas()`, `whereDoesntHave()` and `withCount()`,
     * with the parent key compared as text on PostgreSQL.
     *
     * That query compares two COLUMNS, `"users"."id" = "legal_consents"."subject_id"`, so there is no
     * parameter for the server to infer a type from, and binding cannot help. The cast sits on the
     * PARENT side on purpose: the outer key is one value per outer row, while a cast on
     * `subject_id` would hide the column from the `(subject_type, subject_id)` index every lookup
     * relies on.
     *
     * Only on PostgreSQL. MySQL and SQLite convert on their own, and neither spells the cast the
     * same way (`AS CHAR` on MySQL, `AS TEXT` on SQLite), so the framework's comparison stays
     * exactly what it was there.
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

        if (! $grammar instanceof PostgresGrammar || $base->from === $parentQuery->getQuery()->from) {
            return parent::getRelationExistenceQuery($query, $parentQuery)->select($columns);
        }

        return $query->select($columns)
            ->whereColumn(
                $base->raw('cast('.$grammar->wrap($this->getQualifiedParentKeyName()).' as text)'),
                '=',
                $this->getExistenceCompareKey(),
            )
            ->where($query->qualifyColumn($this->getMorphType()), $this->morphClass);
    }
}
