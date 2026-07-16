<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

/**
 * How a ledger entry was collected — part of the "documentation of the consent
 * workflow at the time of the session" the controller must be able to reconstruct
 * (EDPB 05/2020 Rz. 108).
 */
enum ConsentMethod: string
{
    /** Checkbox on the registration form (the strongest proof context). */
    case RegistrationCheckbox = 'registration_checkbox';

    /** The re-consent gate shown after a material change took effect. */
    case ReConsentGate = 're_consent_gate';

    /** A toggle on the account's legal settings page. */
    case SettingsToggle = 'settings_toggle';

    /** An explicit scroll-to-accept interaction. */
    case ScrollToAccept = 'scroll_to_accept';

    /** The headless JSON API. */
    case Api = 'api';

    /** System-generated deemed acceptance: a deemed-consent objection window closed with no
     * objection, so silence is deemed acceptance (§ 308 Nr. 5 BGB Zustimmungsfiktion). */
    case DeemedAcceptance = 'deemed_acceptance';

    /** Backfilled from a legacy source — weaker proof (no captured wording). */
    case Import = 'import';
}
