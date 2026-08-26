<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

/**
 * What happened in a single append-only ledger entry.
 *
 * The action, together with the document type, is what makes the ledger legally
 * meaningful: a privacy notice is only ever "acknowledged", a real consent is
 * "granted" and may later be "withdrawn", and a material change forces a
 * "re_accepted" entry rather than silently reusing the old one.
 */
enum ConsentAction: string
{
    /** A real consent (Art. 6(1)(a)) was actively given. */
    case Granted = 'granted';

    /** Terms/privacy were accepted / taken notice of (Art. 13, § 305 II BGB). */
    case Acknowledged = 'acknowledged';

    /** Re-consent after a material change (EDPB 05/2020 Rz. 110). */
    case ReAccepted = 're_accepted';

    /** A consent was withdrawn (Art. 7(3)); only valid for ConsentOptin. */
    case Withdrawn = 'withdrawn';

    /** A document was actively declined. */
    case Declined = 'declined';

    /** Parental consent for a subject below the age threshold (Art. 8). */
    case Parental = 'parental';

    /**
     * A Widerspruch: the subject actively objected — either rebutting a deemed-consent
     * fiction (§ 308 Nr. 5 lit. a BGB) or exercising the Art. 21 right to object to
     * legitimate-interest processing. Not an accepting action.
     */
    case Objected = 'objected';

    /**
     * The subject exercised a free right to terminate before a change took effect
     * (§ 675g / § 327r Abs. 3 BGB / P2B). Not an accepting action.
     */
    case Terminated = 'terminated';

    /**
     * System-generated: a deemed-consent objection window elapsed with no objection, so
     * silence is deemed acceptance (Zustimmungsfiktion). IS an accepting action — this is
     * how "silence binds" becomes provable in the ledger rather than merely asserted.
     */
    case DeemedAccepted = 'deemed_accepted';

    /**
     * The FIRST half of a double opt-in: the subject entered themselves, and nobody has yet shown
     * that the person who did so controls the address. NOT an accepting action.
     *
     * That is the whole reason this case exists rather than a second `Granted` row. For advertising
     * e-mail the confirmed double opt-in is the German benchmark (§ 7 Abs. 2 UWG together with
     * Art. 7 DSGVO), the burden of proof lies with the controller (Art. 7(1)) — and an unconfirmed
     * entry is precisely NOT a valid consent. Two `Granted` rows with different timestamps cannot
     * say which one was the confirmation; whoever had to prove it could only assert that the second
     * one was.
     *
     * It does not END a holding either, which is why the fold in {@see ConsentGate} treats it like
     * an objection: re-declaring an interest in something already held must not silently drop the
     * consent that was there.
     *
     * The value is `optin_requested` and not the case name spelled out, because `action` is a
     * 20-character column. SQLite does not enforce that and Postgres and MySQL do, so a longer
     * value would pass the fast suite and fail on the engines a consumer actually runs.
     * The suite holds the whole set against the schema.
     */
    case OptInRequested = 'optin_requested';

    /**
     * The SECOND half of a double opt-in: the subject followed the confirmation link, so the
     * declaration and the address are now tied to the same person. IS an accepting action — this
     * is the row that makes the consent held, and the one an Art. 7(1) demand is answered with.
     */
    case Confirmed = 'confirmed';

    /**
     * The actions that count as a subject currently holding a document: they raise
     * the highest-accepted major version the re-consent gate compares against.
     *
     * @return list<self>
     */
    public static function accepting(): array
    {
        return [self::Granted, self::Acknowledged, self::ReAccepted, self::Parental, self::DeemedAccepted, self::Confirmed];
    }

    /**
     * The actions that leave whatever standing existed before them UNCHANGED — neither accepting
     * nor ending.
     *
     * Both members are here for the same reason, and it is not a resemblance between them: the
     * fold in {@see ConsentGate} is a two-way switch, so anything not accepting counts as ending
     * and drops the holding to zero. An objection rebuts a *deemed change* (§ 308 Nr. 5 lit. a
     * BGB), not the agreement; an opt-in request is a declaration awaiting confirmation. Letting
     * either reset the holding would erase a consent the subject still has, in a ledger nothing
     * can correct afterwards.
     *
     * @return list<self>
     */
    public static function neutral(): array
    {
        return [self::Objected, self::OptInRequested];
    }

    /** Whether this action leaves the prior standing untouched. See {@see neutral()}. */
    public function isNeutral(): bool
    {
        return in_array($this, self::neutral(), true);
    }

    /**
     * Whether this action represents holding (rather than withdrawing/declining) a
     * document at the time it was recorded.
     */
    public function isAccepting(): bool
    {
        return in_array($this, self::accepting(), true);
    }
}
