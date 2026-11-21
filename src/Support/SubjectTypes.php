<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Every spelling a subject's type can stand under in the ledgers: the one `getMorphClass()` returns
 * today, and the class name where a morph map gives the class an alias.
 *
 * An application that registers a map after its ledgers hold rows has older rows under the class
 * name and newer ones under the alias. The older rows cannot be rewritten: `subject_type` is part of
 * every row's hash, so a rewritten row no longer matches the link its successor carries, and
 * `legal_notices` is an append-only proof of delivery. So the package writes under `getMorphClass()`
 * and reads under every spelling here, and two rows of one subject are one subject to every reader.
 */
final class SubjectTypes
{
    /**
     * The spellings to read a subject's rows under, the current one first.
     *
     * @return non-empty-list<string>
     */
    public static function of(Model $subject): array
    {
        $current = (string) $subject->getMorphClass();

        return $current === $subject::class ? [$current] : [$current, $subject::class];
    }

    /**
     * The spellings of every subject given, each once, for a reader over many subjects.
     *
     * @param  iterable<Model>  $subjects
     * @return list<string>
     */
    public static function ofAll(iterable $subjects): array
    {
        $types = [];

        foreach ($subjects as $subject) {
            foreach (self::of($subject) as $type) {
                $types[$type] = $type;
            }
        }

        return array_values($types);
    }

    /**
     * The spelling a stored type has today: the alias a morph map gives a class name, and the type
     * as stored where the map gives it none. Two rows of one subject come back with one type.
     */
    public static function current(string $type): string
    {
        $alias = array_search($type, Relation::morphMap(), true);

        // Cast because a morph alias may be written as a number, and PHP keeps such an array key as an int.
        return $alias === false ? $type : (string) $alias;
    }

    /**
     * Narrows a query to rows where two columns holding subject types name the same type: the same
     * spelling, or a class name in one and the alias a morph map gives it in the other. The values
     * are bound, and the comparison of the columns stays first, so an index on either column still
     * serves it. Without a map it is the column comparison it always was.
     */
    public static function whereSame(QueryBuilder $query, string $first, string $second): QueryBuilder
    {
        $map = Relation::morphMap();

        return $query->where(static function (QueryBuilder $same) use ($first, $second, $map): void {
            $same->whereColumn($first, $second);

            foreach ($map as $alias => $class) {
                $same->orWhere(static fn (QueryBuilder $pair): QueryBuilder => $pair->where($first, $class)->where($second, (string) $alias))
                    ->orWhere(static fn (QueryBuilder $pair): QueryBuilder => $pair->where($first, (string) $alias)->where($second, $class));
            }
        });
    }

    /**
     * A column holding a subject type, as SQL that reads every spelling of one subject as the same
     * value: the alias a morph map gives a class wherever the column holds the class name. The class
     * names and the aliases are bindings of the placeholders, in their order, and the text holds
     * nothing but the column and the words of the case. Without a map it is the column as it stands,
     * so an application without one queries exactly as it did.
     *
     * @param  literal-string  $column  a column of the ledger, as the package names it
     * @return array{0: literal-string, 1: list<string>}
     */
    public static function currentIn(string $column): array
    {
        $bindings = [];

        foreach (Relation::morphMap() as $alias => $class) {
            $bindings[] = $class;
            $bindings[] = (string) $alias;
        }

        if ($bindings === []) {
            return [$column, []];
        }

        return ['case '.$column.str_repeat(' when ? then ?', intdiv(count($bindings), 2)).' else '.$column.' end', $bindings];
    }
}
