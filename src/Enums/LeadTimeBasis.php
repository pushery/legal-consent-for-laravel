<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

/**
 * The rule that sets the minimum advance period of a change, so a screen can name it beside the
 * number rather than leave the reader to guess where the number comes from.
 */
enum LeadTimeBasis: string
{
    /** The benchmark of the notice mode: § 308 Nr. 5 BGB for a deemed consent. */
    case ModeBenchmark = 'mode';

    /** A period the law fixes for the regime: § 675g Abs. 1 BGB, P2B Art. 3(2), EECC Art. 105(4). */
    case Statute = 'statute';

    /** The regime's own setting in `notice_periods`, such as `privacy_advance_days`. */
    case RegimeSetting = 'regime_setting';

    /** The document's own `min_lead_days` in the `documents` registry. */
    case DocumentSetting = 'document';

    /** No period at all. */
    case None = 'none';
}
