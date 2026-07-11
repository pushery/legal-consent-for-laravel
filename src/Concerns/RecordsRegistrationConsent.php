<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Concerns;

use Illuminate\Database\Eloquent\Model;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Support\ConsentContext;
use Pushery\LegalConsent\Support\RegistrationConsentDedup;
use Pushery\LegalConsent\Support\RegistrationConsentRecorder;
use Pushery\LegalConsent\Support\RegistrationRules;

/**
 * Way A (recommended): mix this into the app's Fortify `CreateNewUser` action so the
 * consent is recorded in the exact request that created the account — the strongest
 * proof context (EDPB 05/2020 Rz. 108). Sets an idempotency flag so the Registered
 * listener (Way B) does not double-write.
 */
trait RecordsRegistrationConsent
{
    /**
     * @return array<string, list<string>>
     */
    protected function consentRules(): array
    {
        return app(RegistrationRules::class)->required();
    }

    /**
     * @return array<string, string>
     */
    protected function consentMessages(): array
    {
        return app(RegistrationRules::class)->messages();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function recordRegistrationConsent(Model $user, array $input, ?string $locale = null): void
    {
        $request = request();

        // Per-subject dedup so Way A and Way B never double-write the same account, while a
        // long-lived process recording many subjects on the shared request singleton still
        // records each one (see RegistrationConsentDedup).
        if (RegistrationConsentDedup::seen($request, $user)) {
            return;
        }

        app(RegistrationConsentRecorder::class)->record(
            $user,
            $input,
            ConsentContext::fromRequest($request, ConsentMethod::RegistrationCheckbox),
            $locale,
        );
    }
}
