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

    /**
     * An interstitial shown AFTER authentication and BEFORE first use — the only place a first
     * acceptance can happen when there is no registration form to put a checkbox on.
     *
     * It names the MOMENT, not the mechanism, and that is the whole design: OAuth, SSO, an
     * invitation link and a magic link all land here, so the enum does not need a new case per
     * sign-in route. A case called `oauth_consent` would have needed a sibling on the next one.
     *
     * It exists because the alternatives were not gaps but FALSE STATEMENTS, in the one artifact
     * whose entire purpose is to be true. An OAuth application had two choices: RegistrationCheckbox,
     * which asserts a form that does not exist, or — worse — ReConsentGate, which asserts an
     * acceptance AFTER A DOCUMENT CHANGED that never happened. Under an Art. 15 request the second
     * one reads as a history of a re-consent nobody was ever asked for, and the ledger is
     * append-only on purpose, so it cannot be corrected afterwards.
     */
    case FirstUseGate = 'first_use_gate';

    /**
     * A confirmation inside a transaction — shown in a checkout, before the purchase completes.
     *
     * It exists for the reason {@see self::FirstUseGate} spells out above, applied one case
     * further: the alternatives are not gaps but FALSE STATEMENTS. `RegistrationCheckbox` asserts a
     * sign-up form that was not involved; `ReConsentGate` asserts an acceptance after a document
     * changed, which never happened; `FirstUseGate` names the moment "after signing up, before
     * first use" and this is not that — it is shown before EVERY purchase, to people who have used
     * the application for months. `Api` is the headless channel, a statement about how the row
     * arrived rather than about what the reader was doing.
     *
     * The ledger is append-only, so a row filed under any of them cannot be corrected later; under
     * an Art. 15 request it would describe a screen the person never saw.
     */
    case TransactionGate = 'transaction_gate';

    /** A toggle on the account's legal settings page. */
    case SettingsToggle = 'settings_toggle';

    /** An explicit scroll-to-accept interaction. */
    case ScrollToAccept = 'scroll_to_accept';

    /** The headless JSON API. */
    case Api = 'api';

    /** System-generated deemed acceptance: a deemed-consent objection window closed with no
     * objection, so silence is deemed acceptance (§ 308 Nr. 5 BGB Zustimmungsfiktion). */
    case DeemedAcceptance = 'deemed_acceptance';

    /**
     * The subject followed the confirmation link in a double opt-in e-mail.
     *
     * A channel of its own, not a variant of the form the request came from: the two halves of a
     * double opt-in are collected in different places, and what makes the second one worth
     * anything is exactly that it arrived through the address being confirmed. A confirmation
     * filed under the method of the form that started it would erase the only fact it proves.
     */
    case DoubleOptIn = 'double_opt_in';

    /** Backfilled from a legacy source — weaker proof (no captured wording). */
    case Import = 'import';
}
