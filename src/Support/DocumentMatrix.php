<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * The configured matrix: every registered document key against every locale the operator publishes
 * documents in.
 *
 * It exists so the two places that need the whole matrix — the bulk publish and the doctor's
 * unpublished-combination report — cannot disagree about what "everything" means. A command that
 * publishes one set while the report checks another would tell an operator their installation is
 * incomplete right after the command said it was done.
 *
 * `locales` here is the operator's DOCUMENT locales — which contracts exist — and not the seven
 * locales the package ships its own UI strings in. The two move independently on purpose.
 */
final readonly class DocumentMatrix
{
    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        $documents = config('legal-consent.documents', []);

        if (! is_array($documents)) {
            return [];
        }

        $keys = [];

        foreach ($documents as $key => $definition) {
            // An integer key is one of two things, and only the value tells them apart. `'2024' =>
            // [...]` is a document whose key looks like a number, which registration records and
            // check-drift and cache-flush handle as '2024'. An entry of a config written as a list,
            // `['terms', 'privacy']`, is a bare name that defines nothing.
            if (is_int($key) && ! is_array($definition)) {
                continue;
            }

            $keys[] = (string) $key;
        }

        return $keys;
    }

    /**
     * An empty or missing list falls back to the default locale, so a host that never listed any
     * still has a matrix of one column rather than none — an empty matrix would make the bulk
     * publish a silent no-op and the doctor's report vacuously clean.
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        $locales = config('legal-consent.locales', []);
        $configured = is_array($locales) ? array_values(array_filter($locales, is_string(...))) : [];

        if ($configured !== []) {
            return $configured;
        }

        $default = config('legal-consent.default_locale', 'de');

        return [is_string($default) && $default !== '' ? $default : 'de'];
    }
}
