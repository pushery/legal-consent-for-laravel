<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

use ValueError;

/**
 * The three legally distinct kinds of document a user interacts with at sign-up.
 *
 * They are NOT the same operation: bundling them (the common mistake) is a GDPR
 * violation. Each carries a different legal basis, UI requirement, and retention
 * rule:
 *
 * - ContractTerms  — Vertrag, Art. 6(1)(b) DSGVO / § 305 II BGB. Blocking, not
 *                    withdrawable (ends via cancellation, not withdrawal).
 * - PrivacyNotice  — Informationspflicht, Art. 13/14 DSGVO. The user only takes
 *                    NOTICE ("zur Kenntnis genommen"); never "ich willige ein"
 *                    (EDPB 05/2020 Rz. 122, Binding Decision 5/2022).
 * - ConsentOptin   — echte Einwilligung, Art. 6(1)(a)/Art. 7 DSGVO. Voluntary,
 *                    granular, never a precondition (Kopplungsverbot Art. 7(4)),
 *                    withdrawable at any time (Art. 7(3)).
 */
enum DocumentType: string
{
    case ContractTerms = 'contract_terms';
    case PrivacyNotice = 'privacy_notice';
    case ConsentOptin = 'consent_optin';

    /**
     * Map a config `legal_basis` string to its document type.
     */
    public static function fromLegalBasis(string $basis): self
    {
        // Fail loud on an unknown basis rather than defaulting: silently treating a typo as
        // 'contract' would mis-classify a document's legal nature (blocking vs. voluntary,
        // withdrawable vs. not) — a legal, not cosmetic, error. The message names the fix.
        return match ($basis) {
            'contract' => self::ContractTerms,
            'acknowledgement' => self::PrivacyNotice,
            'consent' => self::ConsentOptin,
            default => throw new ValueError(
                "Unknown legal_basis '{$basis}' in the legal-consent config. Use one of: 'contract' (Vertrag, Art. 6(1)(b)), 'acknowledgement' (Datenschutzhinweis, Art. 13/14), or 'consent' (Einwilligung, Art. 6(1)(a))."
            ),
        };
    }

    /**
     * Only a real consent (Art. 6(1)(a)) needs an explicit, active opt-in. Terms
     * and privacy are mandatory and are accepted/acknowledged, not opted into.
     */
    public function requiresExplicitOptin(): bool
    {
        return $this === self::ConsentOptin;
    }

    /**
     * Mandatory documents (contract + privacy) gate access; a real consent never
     * does (Art. 7(4) Kopplungsverbot).
     */
    public function isMandatory(): bool
    {
        return $this !== self::ConsentOptin;
    }

    /**
     * Withdrawal (Art. 7(3)) applies only to a real consent. A contract ends via
     * cancellation and a privacy notice is information, so neither is withdrawable.
     */
    public function isWithdrawable(): bool
    {
        return $this === self::ConsentOptin;
    }

    /**
     * The GDPR legal basis family, used to pick UI wording and validation rules.
     */
    public function legalBasis(): string
    {
        return match ($this) {
            self::ContractTerms => 'contract',
            self::PrivacyNotice => 'acknowledgement',
            self::ConsentOptin => 'consent',
        };
    }

    /**
     * The action recorded when a subject first accepts a document of this type:
     * a real consent is granted, everything else is acknowledged.
     */
    public function defaultAcceptAction(): ConsentAction
    {
        return $this === self::ConsentOptin
            ? ConsentAction::Granted
            : ConsentAction::Acknowledged;
    }
}
