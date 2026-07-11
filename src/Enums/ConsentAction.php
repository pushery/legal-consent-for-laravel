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
     * The actions that count as a subject currently holding a document: they raise
     * the highest-accepted major version the re-consent gate compares against.
     *
     * @return list<self>
     */
    public static function accepting(): array
    {
        return [self::Granted, self::Acknowledged, self::ReAccepted, self::Parental];
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
