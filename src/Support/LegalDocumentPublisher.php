<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Pushery\LegalConsent\Content\Document;
use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Content\SourceFactory;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Events\LegalDocumentPublished;
use Pushery\LegalConsent\Exceptions\LeadTimeTooShortException;
use Pushery\LegalConsent\Models\LegalDocument;
use RuntimeException;

/**
 * Freezes the current source text of a document into a new, immutable, active
 * `legal_documents` version — the artifact the gate enforces and the ledger snapshots.
 *
 * The change's notice mode (see NoticeMode) is a required human decision, never inferred.
 * Publish-time guards fail LOUD on a legally impossible combination — a deemed consent on
 * a privacy notice, a hard-blocking re-consent on a privacy notice, a material contract or
 * consent change smuggled in under a non-gating mode — because a silent mis-classification
 * is a legal error, not a cosmetic one. Re-publishing the same version with identical
 * content is a no-op; with different content it is refused (bump the version instead).
 */
final readonly class LegalDocumentPublisher
{
    /** Minimum days between announcement and enforcement for a material change. */
    public const int MATERIAL_MIN_LEAD_DAYS = 60;

    /**
     * The STATUTORY floor for a payment framework contract: § 675g Abs. 1 BGB / Art. 54 PSD2 fix
     * two months. Unlike the § 308 Nr. 5 / § 327r "angemessene Frist" (unquantified, so its
     * default is tunable), this one is law — neither config nor a per-document override may go
     * below it.
     */
    public const int PSD2_STATUTORY_MIN_LEAD_DAYS = 60;

    /** Suggested minimum for a scheduled minor change (not enforced — minors never gate). */
    public const int MINOR_MIN_LEAD_DAYS = 14;

    /** The regimes a change may declare; an unknown one would fail OPEN onto a tunable default. */
    public const array REGIMES = ['bgb_agb', 'psd2_675g', 'dcd_327r', 'gdpr', 'p2b', 'eecc'];

    /**
     * @param  array<string, array<string, mixed>>  $documents
     */
    public function __construct(
        private SourceFactory $sources,
        private RenderPipeline $pipeline,
        private array $documents,
    ) {}

    /**
     * Backward-compatible entry point: a boolean materiality flag, mapped to a notice
     * mode (true -> active re-consent, false -> silent editorial). Pre-v0.3.0 callers and
     * the `--material` / `--editorial` command path keep working unchanged.
     */
    public function publish(
        string $key,
        string $locale,
        bool $isMaterial,
        ?CarbonImmutable $announceAt = null,
        ?CarbonImmutable $enforceAt = null,
    ): LegalDocument {
        return $this->publishWithMode(
            $key,
            $locale,
            NoticeMode::fromLegacyReconsent($isMaterial),
            announceAt: $announceAt,
            enforceAt: $enforceAt,
        );
    }

    /**
     * Freeze a new version under an explicit notice mode plus its change-class metadata.
     */
    public function publishWithMode(
        string $key,
        string $locale,
        NoticeMode $mode,
        ?string $changeClass = null,
        ?string $regime = null,
        ?CarbonImmutable $announceAt = null,
        ?CarbonImmutable $enforceAt = null,
        ?CarbonImmutable $objectionDeadline = null,
        bool $offersTermination = false,
        bool $keepsUnmodified = false,
    ): LegalDocument {
        $this->assertLocaleSupported($locale);
        $this->assertRegimeKnown($regime, $key);

        $rendered = $this->pipeline->process($this->sources->for($key)->resolve($key, $locale));
        $type = $this->typeFor($key);

        // The legal invariants are asserted BEFORE the identical-content short-circuit below: an
        // early return must never become a path around them (re-publishing unchanged text under a
        // deemed-consent mode would otherwise skip "deemed consent is contract-only" entirely).
        $this->assertModeAllowedForType($mode, $type, $key);
        $this->assertModeConsistentAcrossLocales($mode, $key, $locale, $rendered->majorVersion);

        // Refuse a downgrade BEFORE the identical-version lookup below. This placement is
        // load-bearing: the `$existing` branch re-activates an inactive matching row, so an
        // inactive lower version whose row still exists (v1 after v2 went active) would
        // otherwise be silently re-activated — retroactively un-gating everyone. The plain
        // `>` major check further down runs only after that branch has already returned, so
        // it never guards this vector. Version is monotonic by design; a revert is a new,
        // higher version carrying the old text, never a re-activation of an old row.
        $this->assertNotDowngrade($key, $locale, $rendered);

        $existing = LegalDocument::query()
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('version', $rendered->version)
            ->first();

        if ($existing instanceof LegalDocument) {
            if ($existing->content_hash === $rendered->contentHash) {
                // Identical text, different classification is NOT a no-op: the notice mode decides
                // the notice duty, so silently keeping the old one would let an operator "fix" a
                // mis-published editorial change into a deemed-consent one, get a success message,
                // and have no notice ever go out. A re-classification needs a new version.
                if ($existing->noticeMode() !== $mode) {
                    throw new RuntimeException(
                        "Version {$rendered->version} of '{$key}' ({$locale}) already exists as {$existing->noticeMode()->value}; re-publishing identical content cannot re-classify it as {$mode->value} — bump the version before publishing."
                    );
                }

                if (! $existing->is_active) {
                    $existing->activate();
                }

                return $existing;
            }

            throw new RuntimeException(
                "Version {$rendered->version} of '{$key}' ({$locale}) already exists with different content — bump the version before publishing."
            );
        }

        $previousMajor = LegalDocument::query()
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->value('major_version');

        $isMajorBump = $previousMajor !== null && is_numeric($previousMajor) && $rendered->majorVersion > (int) $previousMajor;

        if ($isMajorBump) {
            $this->assertMajorBumpMode($mode, $type, $rendered->version, $key, $locale);
        }

        $now = CarbonImmutable::now();

        $announce = $announceAt ?? $rendered->announceAt ?? $now;
        $enforce = $enforceAt ?? $rendered->enforceAt ?? $now;

        if ($mode === NoticeMode::DeemedConsent) {
            $deadline = $this->assertObjectionWindow($objectionDeadline, $enforce, $key);

            // For a deemed-consent change the statutory period is the OBJECTION window, not the
            // span to the effective date: the subject must have the full period to actually
            // object (§ 308 Nr. 5 lit. a BGB; § 675g Abs. 1 for a payment contract). Measuring
            // to enforce_from would let a 61-day announce→enforce span ship a one-day window to
            // object. It is also NEVER waived for an "immediate" publish — silence cannot bind
            // without a real chance to object, so there is no exemption here.
            $minDays = $this->minLeadDays($mode, $regime, $key);

            if ($announce->addDays($minDays)->greaterThan($deadline)) {
                throw LeadTimeTooShortException::for($key, $minDays, $announce, $deadline);
            }
        } elseif ($mode->gates() && $enforce->greaterThan($now)) {
            // A gating (active re-consent) change SCHEDULED for a future enforcement date must
            // give the minimum advance period for its regime between its (effective)
            // announcement and enforcement. Defaulting the announce date to now before comparing
            // closes the bypass where enforceAt is set but announceAt is omitted. An immediate
            // publish (enforcement now-or-past — e.g. an initial version) has no grace window.
            $minDays = $this->minLeadDays($mode, $regime, $key);

            if ($announce->addDays($minDays)->greaterThan($enforce)) {
                throw LeadTimeTooShortException::for($key, $minDays, $announce, $enforce);
            }
        }

        $document = LegalDocument::query()->forceCreate([
            'key' => $key,
            'type' => $type,
            'requires_explicit_optin' => $type->requiresExplicitOptin(),
            'locale' => $locale,
            'version' => $rendered->version,
            'major_version' => $rendered->majorVersion,
            'minor_version' => $rendered->minorVersion,
            'patch_version' => $rendered->patchVersion,
            'title' => $rendered->title,
            'content_format' => 'html',
            'content' => $rendered->html,
            'content_hash' => $rendered->contentHash,
            'ui_wording' => $rendered->uiWording,
            'source_driver' => $this->sourceNameFor($key),
            'source_reference' => $rendered->sourceRef,
            'notice_mode' => $mode,
            'requires_reconsent' => $mode->gates(),
            'change_class' => $changeClass,
            'regime' => $regime,
            'notice_period_days' => (int) round($announce->diffInDays($enforce)),
            'offers_termination' => $offersTermination,
            'keeps_unmodified_offered' => $keepsUnmodified,
            'is_active' => false,
            'published_at' => $now,
            'announce_from' => $announce,
            'enforce_from' => $enforce,
            'objection_deadline' => $objectionDeadline,
        ]);

        $document->activate();

        event(new LegalDocumentPublished($document));

        return $document;
    }

    /**
     * Refuse to publish a version strictly lower than the one currently active for
     * (key, locale). A downgrade is never a legitimate operation on a proof artifact: the
     * active version is the text the population is gated against, and re-activating an older
     * row would silently un-gate everyone who already accepted the newer major. Compared as a
     * (major, minor, patch) tuple; an equal version is not a downgrade (it is the identical-
     * version republish handled downstream), and there is intentionally no `--rollback` — a
     * revert publishes a higher version carrying the old text.
     */
    private function assertNotDowngrade(string $key, string $locale, Document $rendered): void
    {
        $active = LegalDocument::query()
            ->select(['type', 'major_version', 'minor_version', 'patch_version', 'version'])
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();

        if (! $active instanceof LegalDocument) {
            return;
        }

        // A binding document may never be reclassified as informational. Before that class
        // existed a type switch changed WHICH duty applied; it could not remove the duty
        // altogether. Now it can: subjects have accepted v1 of the contract, the operator edits
        // `legal_basis` while tidying the registry, publishes v2 — and the gate silently stops
        // asking anyone, while the ledger still holds the old acceptances so nothing looks wrong.
        // This is the same class of silent legal downgrade the version check below prevents, and
        // it fails just as loudly. The reverse direction stays allowed: becoming stricter is safe.
        if ($active->type->isConsentBearing() && ! $this->typeFor($key)->isConsentBearing()) {
            throw new RuntimeException(
                "Cannot publish '{$key}' ({$locale}) as informational: its active version {$active->version} is a {$active->type->value} that subjects have been asked to accept. An informational page binds nobody, so this would silently remove the gate while their recorded acceptances stay on file. Publish it under its existing legal basis, or retire the document and register the page under a new key."
            );
        }

        // PHP compares equal-length lists element by element, so this is a (major, minor,
        // patch) tuple comparison: true only when the incoming version is strictly lower.
        $current = [(int) $active->major_version, (int) $active->minor_version, (int) $active->patch_version];
        $incoming = [$rendered->majorVersion, $rendered->minorVersion, $rendered->patchVersion];

        if ($incoming < $current) {
            throw new RuntimeException(
                "Cannot publish version {$rendered->version} of '{$key}' ({$locale}): it is lower than the active version {$active->version}. Versions are monotonic — publish a higher version carrying the reverted text instead of re-activating an old one."
            );
        }
    }

    /**
     * One (key, major) is ONE change, so every locale of it must carry the SAME notice mode.
     *
     * Acceptance is identity-keyed — accepting `terms` major 2 in any language satisfies the gate
     * for `terms` major 2 everywhere. If the same major were DeemedConsent in one locale and
     * ActiveReconsent in another, a subject bound by silence in the first would silently satisfy
     * the hard re-consent gate of the second: a weaker proof standing in for a stronger one. The
     * atomic releaser makes this unreachable by construction; this guard covers the per-locale CLI
     * escape hatch, which cannot see its sibling locales.
     */
    private function assertModeConsistentAcrossLocales(NoticeMode $mode, string $key, string $locale, int $major): void
    {
        $siblings = LegalDocument::query()
            ->select(['locale', 'notice_mode', 'requires_reconsent'])
            ->where('key', $key)
            ->where('major_version', $major)
            ->where('locale', '!=', $locale)
            ->where('is_active', true)
            ->get();

        foreach ($siblings as $sibling) {
            if ($sibling->noticeMode() !== $mode) {
                throw new RuntimeException(
                    "'{$key}' major {$major} is already active in '{$sibling->locale}' as {$sibling->noticeMode()->value}; publishing '{$locale}' as {$mode->value} would make one change bind by two different standards. Acceptance is identity-keyed, so the weaker one would satisfy the stronger one's gate — release every locale of a version with the same notice mode."
                );
            }
        }
    }

    /**
     * The load-bearing legal invariants, enforced at publish time and independent of the
     * version bump: deemed consent lives only in the contract type, and a privacy notice is
     * never hard-blocked.
     */
    private function assertModeAllowedForType(NoticeMode $mode, DocumentType $type, string $key): void
    {
        // An informational page binds nobody, so every mode except the silent one describes an
        // audience that does not exist: there is no acceptance to deem, none to re-request, and
        // nobody "holds" the page to be notified about it — the notice sweeps resolve their
        // recipients from ledger rows, and this type never writes one. Publishing it is simply
        // making the current text live.
        if (! $type->isConsentBearing() && $mode !== NoticeMode::SilentEditorial) {
            throw new RuntimeException(
                "'{$key}' is an informational page (Impressum, cookie policy) — it binds nobody, so it is published silently. Publish it with --editorial; there is no acceptance to deem or re-request, and no recipient to notify."
            );
        }

        // Zustimmungsfiktion (silence = consent) is lawful only for a contract/terms change
        // (§ 308 Nr. 5 BGB; BGH XI ZR 26/20). A privacy notice is acknowledged, and a real
        // consent can never be deemed (EDPB 05/2020 Rz. 79).
        if ($mode === NoticeMode::DeemedConsent && $type !== DocumentType::ContractTerms) {
            throw new RuntimeException(
                "A deemed-consent (Zustimmungsfiktion) change is lawful only for a contract/terms document; '{$key}' is a {$type->value}. A privacy notice is acknowledged — publish it info-only; a consent is never deemed — publish it as active re-consent."
            );
        }

        // A privacy notice is information: it is acknowledged, never gated. Blocking access
        // to force acknowledgement is unlawful pressure (WP260 rev.01 Rz. 30-31); a material
        // privacy change is info-only.
        if ($mode === NoticeMode::ActiveReconsent && $type === DocumentType::PrivacyNotice) {
            throw new RuntimeException(
                'A privacy notice is information — acknowledged, never gated. Publish a material privacy change info-only (a hard-blocking re-consent would unlawfully pressure the subject; WP260 rev.01 Rz. 30-31).'
            );
        }
    }

    /**
     * A major-version bump means the change is material. What "material" requires depends on
     * the type: a contract or consent change needs active re-consent before it applies
     * (silence cannot bind — BGH XI ZR 26/20); a privacy change is announced info-only, never
     * gated, but must not go out silently (WP260 rev.01 Rz. 29-31).
     */
    private function assertMajorBumpMode(NoticeMode $mode, DocumentType $type, string $version, string $key, string $locale): void
    {
        // A major bump means "material", and material is a statement about what the change asks
        // of a subject. An informational page asks nothing at any version, so there is no mode to
        // force here; assertModeAllowedForType has already pinned it to editorial.
        if (! $type->isConsentBearing()) {
            return;
        }

        if ($type === DocumentType::PrivacyNotice) {
            if ($mode !== NoticeMode::InfoPush) {
                throw new RuntimeException(
                    "Version {$version} of '{$key}' ({$locale}) is a material privacy change (major bump) — publish it info-only so subjects are actively informed (WP260 rev.01 Rz. 29-31); it is never gated and never silent."
                );
            }

            return;
        }

        if (! $mode->gates()) {
            throw new RuntimeException(
                "Version {$version} of '{$key}' ({$locale}) increases the major version, which forces re-consent — publish it as an active re-consent. A material core change cannot ride on silence or mere information (BGH XI ZR 26/20)."
            );
        }
    }

    /**
     * The minimum advance period for a scheduled gating or deemed-consent change, resolved per
     * regime — never one global value.
     *
     * A per-document `min_lead_days` in the `documents` registry may RAISE a period but never
     * undercut a STATUTORY floor: § 675g Abs. 1 BGB / Art. 54 PSD2 fix two months for a payment
     * framework contract, so that floor is a hard minimum, not a default a config can talk down.
     * The § 308 Nr. 5 / § 327r "angemessene Frist" is unquantified, so those defaults may be
     * tuned freely. Falls back to MATERIAL_MIN_LEAD_DAYS.
     */
    private function minLeadDays(NoticeMode $mode, ?string $regime, string $key): int
    {
        $periods = config('legal-consent.notice_periods');
        $periods = is_array($periods) ? $periods : [];

        $lookup = match (true) {
            $regime === 'psd2_675g' => 'psd2_min_days',
            $mode === NoticeMode::DeemedConsent => 'deemed_consent_min_days',
            default => 'active_reconsent_min_days',
        };

        $value = $periods[$lookup] ?? self::MATERIAL_MIN_LEAD_DAYS;
        $resolved = is_int($value) ? $value : self::MATERIAL_MIN_LEAD_DAYS;

        // Clamp the CONFIG too, not just the override: `psd2_min_days` sits in the same published
        // array as the freely-tunable periods, so lowering it looks like an ordinary knob — but
        // § 675g Abs. 1 BGB / Art. 54 PSD2 fix two months. No input path may undercut it.
        if ($regime === 'psd2_675g') {
            $resolved = max($resolved, self::PSD2_STATUTORY_MIN_LEAD_DAYS);
        }

        $override = $this->documents[$key]['min_lead_days'] ?? null;

        if (! is_int($override)) {
            return $resolved;
        }

        // The payment-services period is statutory: an override may only lengthen it.
        return $regime === 'psd2_675g' ? max($override, $resolved) : $override;
    }

    /**
     * A deemed-consent change needs an objection window that closes before the change takes
     * effect, so the subject can actually object in time (§ 308 Nr. 5 lit. a BGB). Returns the
     * validated deadline so the caller can measure the statutory period against it.
     */
    private function assertObjectionWindow(?CarbonImmutable $objectionDeadline, CarbonImmutable $enforce, string $key): CarbonImmutable
    {
        if (! $objectionDeadline instanceof CarbonImmutable) {
            throw new RuntimeException(
                "A deemed-consent change to '{$key}' needs an objection deadline (the Widerspruchsfrist) — silence past it is deemed acceptance (§ 308 Nr. 5 lit. a BGB)."
            );
        }

        if ($objectionDeadline->greaterThanOrEqualTo($enforce)) {
            throw new RuntimeException(
                "The objection deadline ({$objectionDeadline->toDateString()}) for '{$key}' must fall before the effective date ({$enforce->toDateString()}) — the subject must be able to object before the change takes effect."
            );
        }

        return $objectionDeadline;
    }

    /**
     * A regime must be one of the known set. An unrecognized value (a typo like 'psd2_675') would
     * fail OPEN: it falls through to the freely-tunable mode default instead of its statutory
     * period, so a payment change could ship with a two-week window where § 675g demands two
     * months. Fail loud instead.
     */
    private function assertRegimeKnown(?string $regime, string $key): void
    {
        if ($regime !== null && ! in_array($regime, self::REGIMES, true)) {
            throw new RuntimeException(
                "Unknown regime '{$regime}' for '{$key}'. Use one of: ".implode(', ', self::REGIMES).'. An unrecognized regime would silently fall back to a tunable default instead of its statutory notice period.'
            );
        }
    }

    /**
     * Refuse to publish a version in a locale the app does not declare in
     * `legal-consent.locales` — an unlisted locale is almost always a typo, and shipping a
     * document nobody's gate/banner ever looks for is a silent proof gap. When the list is
     * empty/unset, any locale is allowed (no opinion).
     */
    private function assertLocaleSupported(string $locale): void
    {
        $locales = config('legal-consent.locales');

        if (! is_array($locales)) {
            return;
        }

        $supported = array_values(array_filter($locales, is_string(...)));

        if ($supported !== [] && ! in_array($locale, $supported, true)) {
            throw new RuntimeException(
                "Locale '{$locale}' is not in the configured legal-consent.locales (".implode(', ', $supported).'). Add it there, or publish a supported locale.'
            );
        }
    }

    private function typeFor(string $key): DocumentType
    {
        $basis = $this->documents[$key]['legal_basis'] ?? 'contract';

        return DocumentType::fromLegalBasis(is_string($basis) ? $basis : 'contract');
    }

    private function sourceNameFor(string $key): string
    {
        $source = $this->documents[$key]['source'] ?? 'markdown';

        return is_string($source) ? $source : 'markdown';
    }
}
