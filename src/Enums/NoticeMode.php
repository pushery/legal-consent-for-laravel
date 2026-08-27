<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

/**
 * How a published change to a legal text is communicated and enforced — the legal
 * line between "we inform you, nothing to do" and "you must actively agree".
 *
 * A change is NOT one operation. There are four modes, each with a different notice
 * duty, a different effect of the subject's silence, and a different UI. Collapsing
 * them (the common mistake, and what the boolean `requires_reconsent` did) is a legal
 * error in both directions — it either hard-blocks an info-only change or ships a
 * disadvantageous one with no notice at all.
 *
 * - SilentEditorial  — a typo, a clarification, or a purely favorable change: no
 *                      notice duty, the new version simply activates. (§ 308 Nr. 5 BGB
 *                      only bites on fingierte *nachteilige* declarations — EuGH
 *                      C-287/19.)
 * - InfoPush         — a material but info-only change: the subject is actively
 *                      informed on a durable medium, it takes effect regardless, and NO
 *                      action is required (they may object or terminate). The § 675g /
 *                      Finom case, a material privacy-notice update (Art. 13/14 DSGVO,
 *                      WP260 rev.01), a new-purpose notice (Art. 13(3)), P2B/DSA/EECC.
 * - DeemedConsent    — Zustimmungsfiktion: silence = acceptance. Lawful ONLY for a
 *                      minor/peripheral contract change, with an objection window and
 *                      the § 308 Nr. 5 lit. b special warning (BGH XI ZR 26/20).
 * - ActiveReconsent  — the subject must actively accept before the change applies: a
 *                      material/core-bargain T&C change (§§ 305 II, 311 I BGB) or a
 *                      new/expanded real consent (Art. 6(1)(a) DSGVO; never deemed —
 *                      EDPB 05/2020 Rz. 79).
 *
 * Which mode a change is is a case-by-case legal judgment CARRIED as data, never
 * inferred from a content diff (EuGH C-287/19 gives the standard, not a checklist).
 */
enum NoticeMode: string
{
    case SilentEditorial = 'silent_editorial';
    case InfoPush = 'info_push';
    case DeemedConsent = 'deemed_consent';
    case ActiveReconsent = 'active_reconsent';

    /**
     * Map the legacy `requires_reconsent` boolean to a notice mode, so rows and callers
     * written before this enum existed keep working: a re-consent was an active
     * re-consent; everything else was a silent (editorial) activation.
     */
    public static function fromLegacyReconsent(bool $requiresReconsent): self
    {
        return $requiresReconsent ? self::ActiveReconsent : self::SilentEditorial;
    }

    /**
     * Only an active re-consent hard-blocks access via the enforcement middleware.
     * Info-only and deemed-consent changes are announced but NEVER gate (blocking a
     * privacy notice to force acknowledgement is unlawful pressure — WP260 rev.01
     * Rz. 30-31), and an editorial change is not enforceable at all.
     */
    public function gates(): bool
    {
        return $this === self::ActiveReconsent;
    }

    /**
     * Every mode except a silent editorial change owes the subject an actively-pushed
     * notice (a durable medium for a disadvantageous change — CJEU C-375/15 BAWAG);
     * "check the terms regularly" is never enough (GDPR Art. 5(1)(a)).
     */
    public function requiresNotice(): bool
    {
        return $this !== self::SilentEditorial;
    }

    /**
     * Whether the change takes legal effect even if the subject does nothing: an
     * info-only change takes effect regardless, and a deemed-consent change binds on
     * silence (Zustimmungsfiktion). An active re-consent does NOT — the old terms
     * continue until the subject agrees (BGH XI ZR 26/20).
     */
    public function bindsOnSilence(): bool
    {
        return $this === self::InfoPush || $this === self::DeemedConsent;
    }

    /**
     * Only a deemed-consent change runs an objection window — the § 308 Nr. 5 lit. a
     * period plus the lit. b "silence = consent" warning that makes the fiction valid.
     */
    public function usesObjectionWindow(): bool
    {
        return $this === self::DeemedConsent;
    }
}
