<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

use ValueError;

/**
 * The kinds of document a user interacts with, and how each one binds them.
 *
 * The first three are NOT the same operation: bundling them (the common mistake) is
 * a GDPR violation. Each carries a different legal basis, UI requirement, and
 * retention rule:
 *
 * - ContractTerms  — Vertrag, Art. 6(1)(b) DSGVO / § 305 II BGB. Blocking, not
 *                    withdrawable (ends via cancellation, not withdrawal).
 * - PrivacyNotice  — Informationspflicht, Art. 13/14 DSGVO. The user only takes
 *                    NOTICE ("zur Kenntnis genommen"); never "ich willige ein"
 *                    (EDPB 05/2020 Rz. 122, Binding Decision 5/2022).
 * - ConsentOptin   — echte Einwilligung, Art. 6(1)(a)/Art. 7 DSGVO. Voluntary,
 *                    granular, never a precondition (Kopplungsverbot Art. 7(4)),
 *                    withdrawable at any time (Art. 7(3)).
 *
 * The fourth is different in kind, and that is the point:
 *
 * - Informational  — a page that is PUBLISHED but binds nobody: Impressum (§ 5 DDG),
 *                    a cookie policy, an accessibility statement. It is a legal page
 *                    an operator must publish and keep current, and it asks the
 *                    reader for nothing at all.
 *
 * Informational exists so such a page can use the draft store — the editor, the
 * review gate, the translation seam, the sanitizing pipeline, the frozen published
 * row — WITHOUT acquiring consent semantics. Before it, registering an Impressum
 * meant a checkbox at sign-up, because every registered document produced one; the
 * only way out was to keep those pages outside the package entirely and lose all of
 * that machinery for them.
 */
enum DocumentType: string
{
    case ContractTerms = 'contract_terms';
    case PrivacyNotice = 'privacy_notice';
    case ConsentOptin = 'consent_optin';
    case Informational = 'informational';

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
            'informational' => self::Informational,
            default => throw new ValueError(
                "Unknown legal_basis '{$basis}' in the legal-consent config. Use one of: 'contract' (Vertrag, Art. 6(1)(b)), 'acknowledgement' (Datenschutzhinweis, Art. 13/14), 'consent' (Einwilligung, Art. 6(1)(a)), or 'informational' (a published page that binds nobody — Impressum, cookie policy)."
            ),
        };
    }

    /**
     * Does this document ask the subject for ANYTHING — an acceptance, an
     * acknowledgement, or a consent?
     *
     * This is the predicate that keeps an informational page out of the registration
     * form, the gate, and the notice sweeps. It is deliberately NOT expressible as
     * `! isMandatory()`: that already means "ConsentOptin", which is precisely a
     * document that DOES ask something (an optional checkbox). Asking whether a
     * document is mandatory and asking whether it binds at all are two questions, and
     * conflating them is what forced an Impressum to appear at sign-up.
     */
    public function isConsentBearing(): bool
    {
        return $this !== self::Informational;
    }

    /**
     * Only a real consent (Art. 6(1)(a)) needs an explicit, active opt-in. Terms
     * and privacy are mandatory and are accepted/acknowledged, not opted into; an
     * informational page is never opted into either, because it is never shown as a
     * control.
     */
    public function requiresExplicitOptin(): bool
    {
        return $this === self::ConsentOptin;
    }

    /**
     * Mandatory documents (contract + privacy) gate access; a real consent never
     * does (Art. 7(4) Kopplungsverbot), and neither does an informational page —
     * it has nothing to gate ON, since no subject ever accepts one.
     */
    public function isMandatory(): bool
    {
        return $this === self::ContractTerms || $this === self::PrivacyNotice;
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
            self::Informational => 'informational',
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
