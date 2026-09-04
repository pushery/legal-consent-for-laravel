<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pushery\LegalConsent\Content\Document;
use Pushery\LegalConsent\Content\LegalDocumentSource;
use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Content\SourceFactory;
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Events\LegalDocumentPublished;
use Pushery\LegalConsent\Exceptions\LeadTimeTooShortException;
use Pushery\LegalConsent\Exceptions\NoticeTimelineInvertedException;
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
     * Which `notice_periods` key each regime resolves its advance-notice period from, or null when
     * the regime sets no separate period of its own.
     *
     * TOTAL over {@see REGIMES} on purpose, and held that way by a test. The original defect was
     * that four period keys sat in a published config file with no reader at all: an operator who
     * raised `p2b_standstill_days` because their contract demanded it changed nothing, and nothing
     * said so. A partial map would let the next regime arrive the same way, so a regime with no
     * period has to say `null` here rather than simply be absent.
     *
     * The two nulls are decisions, not gaps:
     *  - `bgb_agb` — § 308 Nr. 5's "angemessene Frist" IS the mode benchmark below; a second
     *    number would be the same rule written twice.
     *  - `dcd_327r` — § 327r Abs. 2 requires notice "within a reasonable period before" and fixes
     *    no number. `dcd_termination_days` is NOT that number: it is the 30-day free-termination
     *    window of § 327r Abs. 3, which runs from the LATER of notice and modification, so reading
     *    it as a lead time would assert a statutory advance period that does not exist.
     */
    private const array REGIME_PERIOD_KEYS = [
        'bgb_agb' => null,
        'psd2_675g' => 'psd2_min_days',
        'dcd_327r' => null,
        'gdpr' => 'privacy_advance_days',
        'p2b' => 'p2b_standstill_days',
        'eecc' => 'eecc_min_days',
    ];

    /**
     * Regimes whose advance-notice period is fixed BY LAW. Config and a per-document override may
     * lengthen one, never shorten it — the same rule PSD2 already had, applied to the two other
     * regimes that quantify their own minimum.
     *
     * `gdpr` is deliberately absent: WP260's "well in advance" is guidance, not a number, so its
     * default is a sane one and an operator may tune it down.
     */
    private const array STATUTORY_FLOORS = [
        'psd2_675g' => self::PSD2_STATUTORY_MIN_LEAD_DAYS, // § 675g Abs. 1 BGB / Art. 54 PSD2 — two months
        'p2b' => 15,                                       // Reg. (EU) 2019/1150 Art. 3(2) — at least 15 days
        'eecc' => 30,                                      // Dir. (EU) 2018/1972 Art. 105(4) — not less than one month
    ];

    /**
     * @param  array<string, array<string, mixed>>  $documents
     */
    public function __construct(
        private SourceFactory $sources,
        private RenderPipeline $pipeline,
        private array $documents,
        private ChangeItemsFreezer $changeItems = new ChangeItemsFreezer,
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
     * Resolve and render what a publish WOULD freeze, writing nothing.
     *
     * It lives here rather than in the command because the source factory does: a caller that
     * resolved its own factory would read a different source than the publish it is previewing,
     * and a dry run whose answer comes from somewhere else than the real run is worse than none.
     * Every exception the real path raises on resolution raises here too, unchanged — that is
     * what makes "it would fail" a finding a dry run can report.
     */
    public function preview(string $key, string $locale): Document
    {
        return $this->pipeline->process($this->sources->for($key)->resolve($key, $locale));
    }

    /**
     * The resolved source behind a document key.
     *
     * Exposed so a caller can ask what KIND of source it is without building a second factory.
     * The bulk publish needs it to tell an unwritten draft from a missing file — both raise the
     * same exception, so the answer is on the source, not on the failure. Going through the
     * publisher rather than resolving a factory from the container is what keeps that answer
     * consistent with the source the publish itself used.
     */
    public function sourceFor(string $key): LegalDocumentSource
    {
        return $this->sources->for($key);
    }

    /**
     * Freeze a new version under an explicit notice mode plus its change-class metadata.
     *
     * Its guards are also the dry run's guards — see {@see previewWithMode}, which runs this
     * method's checks in this method's order and writes nothing.
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
        $this->assertRequestCoherent($key, $locale, $mode, $regime);

        $rendered = $this->preview($key, $locale);
        $type = $this->typeFor($key);

        $this->assertVersionPublishable($key, $locale, $mode, $type, $rendered);

        $existing = $this->existingVersion($key, $locale, $rendered->version);

        if ($existing instanceof LegalDocument) {
            $this->assertRepublishable($existing, $mode, $rendered, $key, $locale);

            if (! $existing->is_active) {
                $existing->activate();
            }

            return $existing;
        }

        if ($this->isMajorBump($key, $locale, $rendered)) {
            $this->assertMajorBumpMode($mode, $type, $rendered->version, $key, $locale);
        }

        $now = CarbonImmutable::now();

        [$announce, $enforce] = $this->assertedSchedule($key, $locale, $mode, $regime, $rendered, $announceAt, $enforceAt, $objectionDeadline, $now);

        // ⚠️ THE ROW AND ITS ACTIVATION ARE ONE ACT, AND THEY USED NOT TO BE.
        // `forceCreate()` persisted the version and `activate()` ran after it, unwrapped. When
        // `activate()` lost the lock race it threw LockTimeoutException — and left the row behind,
        // persisted and inactive. That version is then unrepublishable: the next attempt meets
        // "Version … already exists with different content — bump the version before publishing",
        // for a version the operator never successfully published. A phantom that can only be
        // cleared by hand, in an append-only table.
        //
        // The lock is taken OUTSIDE the transaction, exactly as LegalDocumentReleaser does, and the
        // ordering is the whole point: `LegalDocument::activate()` skips its own lock when a
        // transaction is already open (it delegates to the orchestrating caller by design), so
        // wrapping without lifting the lock out would have removed the serialization while looking
        // like it added safety.
        $store = LegalDocument::activationLockStore();

        $write = (fn (): LegalDocument => DB::transaction(function () use (
            $key, $locale, $type, $mode, $regime, $rendered, $changeClass, $offersTermination,
            $keepsUnmodified, $now, $announce, $enforce, $objectionDeadline
        ): LegalDocument {
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
                // Floored at zero, because this is the period that was GRANTED and a granted period is
                // never negative. The guard above rules out an inverted timeline; what remains is the
                // immediate publish whose effective date is already past, where the honest answer is
                // "no grace at all" rather than a negative count of days. The column sits outside
                // MUTABLE_AFTER_PUBLISH, so whatever lands here is frozen — and it is the number a
                // consumer's compliance report reads back as the notice period.
                'notice_period_days' => max(0, (int) round($announce->diffInDays($enforce))),
                'offers_termination' => $offersTermination,
                'keeps_unmodified_offered' => $keepsUnmodified,
                'is_active' => false,
                'published_at' => $now,
                'announce_from' => $announce,
                'enforce_from' => $enforce,
                'objection_deadline' => $objectionDeadline,
            ]);

            // Freeze the operator's description of THIS change onto THIS version, before the row
            // goes active. No new parameter: the freezer finds the draft by (key, locale,
            // tenant), so the ten-argument signature stays as it is and no caller has to learn
            // about the feature to keep working. Absence is not an error — see ChangeItemsFreezer.
            $this->changeItems->freeze($document, $this->sources->for($key)->fingerprint($key, $locale));

            $document->activate();

            return $document;
        }));

        // Degrade and SAY so, the same way the releaser does: `array` and `null` DO implement
        // LockProvider while serializing nothing across processes, so a lock taken on them would
        // imply a protection it is not giving. The write still runs, and it is still ATOMIC — that
        // is the half this change is about, and it holds on every store, locked or not.
        //
        // The condition is evaluated ONCE. Writing it twice — once to choose the path, once to
        // decide whether to warn — is two places to keep in step for one question, and the pair
        // silently disagrees the day somebody edits one.
        $serializes = $store instanceof LockProvider && ! $store instanceof ArrayStore && ! $store instanceof NullStore;

        if (! $serializes) {
            Log::warning('legal-consent: cache store cannot serialize a publish, running unserialized', [
                'document_key' => $key,
                'store' => $store::class,
            ]);
        }

        // The annotation carries what the signature cannot: `block()` returns whatever its callback
        // returns and is typed `mixed`, while `$write` is declared `: LegalDocument`. Same shape,
        // same reason, as LegalDocumentReleaser.
        /** @var LegalDocument $document */
        $document = $serializes
            ? $store->lock(LegalDocument::activationLockName($key), 10)->block(5, $write)
            : $write();

        event(new LegalDocumentPublished($document));

        return $document;
    }

    /**
     * What {@see publishWithMode} WOULD freeze, with every one of its guards applied and nothing
     * written.
     *
     * The signature is the real one deliberately, argument for argument, so a caller previews the
     * publish it is about to make rather than an approximation of it — `--regime` and `--enforce-at`
     * decide whether a run is refused, so a preview that cannot receive them cannot answer for it.
     * `$changeClass`, `$offersTermination` and `$keepsUnmodified` are frozen onto the row and
     * decide nothing, so nothing here reads them; they are accepted so the call site stays a
     * mirror of the publish and a future guard over one of them needs no new signature.
     *
     * ⚠️ THE GUARDS MAY NOT BE REBUILT IN A COMMAND. Every one of them is a legal rule — which
     * locales exist, which notice mode a document type can carry, what a statutory advance period
     * is — and a second copy of a legal rule is a second answer to it, diverging silently from the
     * moment one side is amended. That is why this lives beside the real path and calls the same
     * private methods, rather than being a checklist a caller assembles.
     *
     * The identical-content case returns rather than throws, because the real run does not refuse
     * it either: it returns the existing row untouched. A dry run that invented a refusal there
     * would be as misleading as one that promised a publish the real run refuses.
     */
    public function previewWithMode(
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
    ): Document {
        $this->assertRequestCoherent($key, $locale, $mode, $regime);

        $rendered = $this->preview($key, $locale);
        $type = $this->typeFor($key);

        $this->assertVersionPublishable($key, $locale, $mode, $type, $rendered);

        $existing = $this->existingVersion($key, $locale, $rendered->version);

        if ($existing instanceof LegalDocument) {
            $this->assertRepublishable($existing, $mode, $rendered, $key, $locale);

            return $rendered;
        }

        if ($this->isMajorBump($key, $locale, $rendered)) {
            $this->assertMajorBumpMode($mode, $type, $rendered->version, $key, $locale);
        }

        $this->assertedSchedule($key, $locale, $mode, $regime, $rendered, $announceAt, $enforceAt, $objectionDeadline, CarbonImmutable::now());

        return $rendered;
    }

    /**
     * The guards that need no rendered text — so a typo in a locale or a regime is refused before
     * a source is read.
     */
    private function assertRequestCoherent(string $key, string $locale, NoticeMode $mode, ?string $regime): void
    {
        $this->assertLocaleSupported($locale);
        $this->assertRegimeKnown($regime, $key);
        $this->assertRegimeCoherentWithMode($regime, $mode, $key);
    }

    /**
     * The guards over the rendered version, all of which run BEFORE the identical-content
     * short-circuit: an early return must never become a path around them (re-publishing unchanged
     * text under a deemed-consent mode would otherwise skip "deemed consent is contract-only"
     * entirely).
     *
     * The downgrade refusal belongs here for a sharper reason. The `$existing` branch re-activates
     * an inactive matching row, so an inactive lower version whose row still exists (v1 after v2
     * went active) would otherwise be silently re-activated — retroactively un-gating everyone.
     * The plain `>` major check runs only after that branch has already returned, so it never
     * guards this vector. Version is monotonic by design; a revert is a new, higher version
     * carrying the old text, never a re-activation of an old row.
     */
    private function assertVersionPublishable(string $key, string $locale, NoticeMode $mode, DocumentType $type, Document $rendered): void
    {
        $this->assertModeAllowedForType($mode, $type, $key);
        $this->assertModeConsistentAcrossLocales($mode, $key, $locale, $rendered->majorVersion);
        $this->assertNotDowngrade($key, $locale, $rendered);
    }

    /** The row this version would collide with, if one is already on file. */
    private function existingVersion(string $key, string $locale, string $version): ?LegalDocument
    {
        return LegalDocument::query()
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('version', $version)
            ->first();
    }

    /**
     * Whether an existing row of the same version may be re-published over.
     *
     * Identical text is the only case that passes, and identical text under a DIFFERENT
     * classification is not a no-op: the notice mode decides the notice duty, so silently keeping
     * the old one would let an operator "fix" a mis-published editorial change into a
     * deemed-consent one, get a success message, and have no notice ever go out. A
     * re-classification needs a new version.
     */
    private function assertRepublishable(LegalDocument $existing, NoticeMode $mode, Document $rendered, string $key, string $locale): void
    {
        if ($existing->content_hash !== $rendered->contentHash) {
            throw new RuntimeException(
                "Version {$rendered->version} of '{$key}' ({$locale}) already exists with different content — bump the version before publishing."
            );
        }

        if ($existing->noticeMode() !== $mode) {
            throw new RuntimeException(
                "Version {$rendered->version} of '{$key}' ({$locale}) already exists as {$existing->noticeMode()->value}; re-publishing identical content cannot re-classify it as {$mode->value} — bump the version before publishing."
            );
        }
    }

    /** Whether this version raises the major over the one currently active for (key, locale). */
    private function isMajorBump(string $key, string $locale, Document $rendered): bool
    {
        $previousMajor = LegalDocument::query()
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->value('major_version');

        return $previousMajor !== null && is_numeric($previousMajor) && $rendered->majorVersion > (int) $previousMajor;
    }

    /**
     * The announcement and effective dates a publish would freeze, with every schedule guard
     * applied. Resolution and validation are one method because the guards run on the RESOLVED
     * dates: a caller that resolved them itself and then asked for a check would be checking a
     * different schedule than the one that gets written.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function assertedSchedule(
        string $key,
        string $locale,
        NoticeMode $mode,
        ?string $regime,
        Document $rendered,
        ?CarbonImmutable $announceAt,
        ?CarbonImmutable $enforceAt,
        ?CarbonImmutable $objectionDeadline,
        CarbonImmutable $now,
    ): array {
        $chosenAnnounce = $announceAt ?? $rendered->announceAt;
        $announce = $chosenAnnounce ?? $now;
        $enforce = $enforceAt ?? $rendered->enforceAt ?? $now;

        // The timeline invariant, checked BEFORE the mode branches and therefore independent of
        // mode, regime and minimum lead time. Both branches below could be skipped entirely — the
        // deemed-consent one measures against the objection deadline rather than the effective
        // date, and the other runs only for a mode that owes notice AND an effective date still in
        // the future — so an inverted timeline reached the insert with nothing having looked at it.
        //
        // Only an announcement the operator CHOSE is refused, and the distinction is not
        // squeamishness: an effective date already in the past with no announcement date given is a
        // documented, supported publish ("an immediate gate with no grace" — an initial version, or
        // a change whose effective date was reached before it was published), and it is the
        // announcement that is defaulted there, not chosen. What has never been supported, and what
        // nothing refused, is an operator naming an announcement date AFTER the effective date.
        if ($chosenAnnounce instanceof CarbonImmutable && $announce->greaterThan($enforce)) {
            throw NoticeTimelineInvertedException::for($key, $locale, $announce, $enforce);
        }

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
        } elseif ($mode->requiresNotice() && $enforce->greaterThan($now)) {
            // A change SCHEDULED for a future enforcement date must give the minimum advance
            // period for its mode and regime between its (effective) announcement and enforcement.
            // Defaulting the announce date to now before comparing closes the bypass where
            // enforceAt is set but announceAt is omitted. An immediate publish (enforcement
            // now-or-past — e.g. an initial version) has no grace window.
            //
            // The condition used to be `$mode->gates()`, which is true for ACTIVE RE-CONSENT ONLY.
            // An info-only change therefore hit neither branch and got no advance check at all —
            // while NoticeMode's own docblock assigns P2B, DSA and EECC to exactly that mode. A
            // P2B change with three days' notice went through without a word, and Art. 3(3) makes
            // a change implemented that way VOID. `requiresNotice()` is the honest predicate: a
            // silent editorial change owes no notice and therefore no period, and everything else
            // does. A mode with no benchmark and a regime with no floor still resolves to 0, so
            // nothing that passed before now fails for a reason nobody declared.
            $minDays = $this->minLeadDays($mode, $regime, $key);

            if ($minDays > 0 && $announce->addDays($minDays)->greaterThan($enforce)) {
                throw LeadTimeTooShortException::for($key, $minDays, $announce, $enforce);
            }
        }

        return [$announce, $enforce];
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

        // The two minima are combined with max(), not chosen between. A deemed-consent change
        // under P2B owes BOTH the § 308 Nr. 5 benchmark and the Art. 3 standstill, and letting the
        // regime replace the mode would have cut a 60-day deemed window to 15 — a regression
        // dressed as a feature. An info-only change has no mode benchmark at all, which is why it
        // had no check whatsoever until its regime supplied one.
        $modeMinimum = match ($mode) {
            NoticeMode::DeemedConsent => $this->period($periods, 'deemed_consent_min_days'),
            NoticeMode::ActiveReconsent => $this->period($periods, 'active_reconsent_min_days'),
            default => 0,
        };

        $regimeKey = $regime === null ? null : (self::REGIME_PERIOD_KEYS[$regime] ?? null);
        $regimeMinimum = $regimeKey === null ? 0 : $this->period($periods, $regimeKey);

        // Clamp the CONFIG too, not just the override: a statutory period sits in the same
        // published array as the freely-tunable ones, so lowering it looks like an ordinary knob.
        // No input path may undercut a floor the law fixes.
        $floor = $regime === null ? 0 : (self::STATUTORY_FLOORS[$regime] ?? 0);
        $resolved = max($modeMinimum, $regimeMinimum, $floor);

        $override = $this->documents[$key]['min_lead_days'] ?? null;

        if (! is_int($override)) {
            return $resolved;
        }

        // A statutory period may only be lengthened by an override. A regime without a legal floor
        // — and a change with no regime at all — may be tuned down, which is the point of the knob.
        return $floor > 0 ? max($override, $resolved) : $override;
    }

    /**
     * One `notice_periods` value, falling back to the material default when the published config
     * dropped or corrupted the key.
     *
     * @param  array<array-key, mixed>  $periods
     */
    private function period(array $periods, string $key): int
    {
        $value = $periods[$key] ?? self::MATERIAL_MIN_LEAD_DAYS;

        return is_int($value) ? $value : self::MATERIAL_MIN_LEAD_DAYS;
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
     * A regime declared on a change that owes no notice.
     *
     * Naming a statutory regime says "this change is regulated"; publishing it as a silent
     * editorial change says "no notice is owed". Both cannot be true, and the combination is not
     * merely odd — the regime's advance period is then never applied to anything, which is exactly
     * the shape of the defect this whole area was reported for: a setting that reads as in force
     * and is inert.
     *
     * Deliberately narrow. Whether, say, a P2B change may be published as deemed consent rather
     * than info-push is a legal judgment per case, and a guard that decided it here would be
     * asserting law the package has no business asserting. This one needs no judgment: the mode
     * says there is nothing to announce.
     */
    private function assertRegimeCoherentWithMode(?string $regime, NoticeMode $mode, string $key): void
    {
        if ($regime !== null && ! $mode->requiresNotice()) {
            throw new RuntimeException(
                "Regime '{$regime}' was declared on a silent editorial change to '{$key}'. An editorial change owes no notice, so the regime's advance-notice period would never be applied — publish it without a regime, or classify the change as the one it is (--info, --deemed or --active)."
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
