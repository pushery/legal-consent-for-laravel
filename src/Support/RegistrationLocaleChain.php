<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * The ONE locale-resolution order every registration path uses: what the subject SAW, then the
 * configured `fallback_locale`, then the `default_locale`. Deduplicated and order-preserving, so the
 * first hit is always the closest match to what was displayed.
 *
 * It lives here, shared by {@see RegistrationRules}, {@see DefaultConsentManager::registrationChecklist()}
 * and {@see RegistrationConsentRecorder}, because those three MUST resolve the same document — the
 * rule side, the displayed checklist and the ledger row. `fallback_locale` is a separate config key
 * from `default_locale`; a document published only in the fallback was recorded by the recorder but
 * neither validated nor shown until all three walked this same chain.
 */
final class RegistrationLocaleChain
{
    /**
     * @return list<string>
     */
    public static function resolve(string $seen, string $defaultLocale): array
    {
        $fallback = config('legal-consent.fallback_locale');

        $chain = [$seen];

        if (is_string($fallback) && $fallback !== '') {
            $chain[] = $fallback;
        }

        $chain[] = $defaultLocale;

        return array_values(array_unique($chain));
    }
}
