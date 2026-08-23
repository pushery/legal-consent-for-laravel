<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Pushery\LegalConsent\Models\LegalConsent;

/**
 * The FIRST half of a double opt-in was recorded: somebody entered this subject for a voluntary
 * consent, and nobody has yet shown that whoever did so controls the address.
 *
 * **This is not an acceptance, and it deliberately does not fire `ConsentRecorded`.** That event
 * is what a consuming application provisions on — subscribes the address, enables the feature —
 * and doing any of it now would act on a declaration that is precisely not yet a valid consent
 * (§ 7 Abs. 2 UWG with Art. 7 DSGVO). Falling through to the shared default would have been the
 * silent version of that: the row would say "requested" while every listener heard "granted".
 *
 * What it IS for is sending the confirmation mail. The package does not send it — it owns the
 * ledger, not the mailbox — so this is the seam: listen here, mail a signed link, and call
 * `Consent::confirm()` when it is followed.
 */
final readonly class ConsentConfirmationRequested
{
    public function __construct(public LegalConsent $consent) {}
}
