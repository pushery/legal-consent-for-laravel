<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

/**
 * Whether a human has signed off on a draft's EXACT current bytes.
 *
 * Deliberately separate from staleness: "a human approved this text" and "this text still matches
 * the source it was translated from" are different questions, and conflating them is how a review
 * gate silently stops meaning anything. Only an explicit human act sets Reviewed; every write to a
 * draft's body resets it to Draft.
 */
enum ReviewState: string
{
    /** Being worked on, or changed since the last sign-off. Not publishable. */
    case Draft = 'draft';

    /** A human confirmed these exact bytes. The only state the publish gate accepts. */
    case Reviewed = 'reviewed';
}
