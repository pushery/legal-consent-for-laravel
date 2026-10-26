<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Who concludes a registered document: the model its registry entry names under `concluded_by`, or
 * the person who signs in when the entry names none.
 *
 * A data processing agreement is concluded by the customer an account stands for, a workspace or a
 * team, and not by each person who works in it:
 *
 *     'dpa' => [
 *         'source' => 'markdown',
 *         'legal_basis' => 'contract',
 *         'concluded_by' => App\Models\Workspace::class,
 *     ],
 *
 * Such a document is accepted for an instance of that model, `Consent::accept($workspace, 'dpa', $context)`,
 * and asked about the same way, `Consent::hasCurrent($workspace, 'dpa')`. Everything that holds a person
 * to a document leaves it out for any subject that is not an instance of the model: the registration
 * form (its rules, its checklist and its recorder), the consent gate, the re-consent banner, the status
 * map and the settings screen. Asked about the workspace itself, the gate and the status map include it.
 *
 * A document that names no party binds every subject, exactly as before the key existed. So asking
 * about the workspace also lists the documents the people sign, which the workspace holds nothing for;
 * ask about the one document by its key when that is the question.
 *
 * A change notice goes to the parties that accepted the version before, which here are instances of the
 * model, so Laravel's notification routing decides who reads it: a model that uses `Notifiable` and
 * answers `routeNotificationForMail()` with the owners' addresses sends the notice to them.
 * `legal-consent:doctor` reports a `concluded_by` that names no model, or a model that cannot be
 * notified.
 */
final class ConcludingParty
{
    /**
     * Whether the document registered under `$key` binds `$subject`.
     *
     * A value that is set but names no class binds nobody rather than everybody. Read the other way, a
     * typo in the class name would put a workspace's contract on every person's registration form and
     * in front of every request they make, which is the one outcome the key exists to prevent.
     */
    public static function includes(Model $subject, string $key): bool
    {
        $party = DocumentRegistryEntry::option($key, 'concluded_by');

        if (! self::names($party)) {
            return true;
        }

        return is_string($party) && $subject instanceof $party;
    }

    /** The party the entry under `$key` names, as a message prints it. */
    public static function nameOf(string $key): string
    {
        $party = DocumentRegistryEntry::option($key, 'concluded_by');

        return is_string($party) ? $party : get_debug_type($party);
    }

    /**
     * Whether a configured `concluded_by` names a party. An absent or empty value does not, so an
     * entry built from an unset environment variable behaves like one without the key.
     */
    public static function names(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * What keeps a configured `concluded_by` from working, or null when it names a model a change
     * notice can reach.
     */
    public static function problem(mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'it is '.get_debug_type($value).', not the name of a model class';
        }

        if (! class_exists($value)) {
            return "{$value} does not exist";
        }

        if (! is_subclass_of($value, Model::class)) {
            return "{$value} is not an Eloquent model";
        }

        if (! method_exists($value, 'routeNotificationFor')) {
            return "{$value} cannot receive a change notice, because it does not use Notifiable";
        }

        return null;
    }
}
