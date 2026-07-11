<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Listeners;

use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Model;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Support\ConsentContext;
use Pushery\LegalConsent\Support\RegistrationConsentDedup;
use Pushery\LegalConsent\Support\RegistrationConsentRecorder;

/**
 * Way B (no Fortify): records registration consent from the standard `Registered`
 * event. Respects the Way-A idempotency flag so the two never double-write; it is only
 * registered when config `registration.listen_to_registered_event` is on.
 */
final readonly class RecordConsentOnRegistration
{
    public function __construct(private RegistrationConsentRecorder $recorder) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof Model) {
            return;
        }

        $request = request();

        // De-duplicate Way-A/Way-B per SUBJECT, not per request. The 'request' singleton is
        // process-shared, so a long-lived process (queue/console) that fires Registered for
        // many users on it would let a global boolean record only the first. Keying by the
        // subject's identity still blocks a same-request double-write while letting every
        // distinct subject record exactly once.
        if (RegistrationConsentDedup::seen($request, $user)) {
            return;
        }

        /** @var array<string, mixed> $input */
        $input = $request->all();

        $this->recorder->record(
            $user,
            $input,
            ConsentContext::fromRequest($request, ConsentMethod::RegistrationCheckbox),
        );
    }
}
