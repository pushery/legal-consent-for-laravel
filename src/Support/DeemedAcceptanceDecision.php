<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Models\LegalConsent;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Decides whether silence binds a single subject when a deemed-consent objection window closes.
 *
 * Extracted from the sweep because this is the one judgement in the package that CREATES consent
 * out of inaction (§ 308 Nr. 5 BGB) — it deserves to be readable and testable on its own rather
 * than buried in a streaming loop where its branches are only reachable under real concurrency.
 */
final class DeemedAcceptanceDecision
{
    /**
     * @param  LegalConsent|null  $latest  the subject's most recent action for this document+locale,
     *                                     read live (NOT from the sweep's ledger snapshot)
     */
    public function shouldDeem(?LegalConsent $latest, LegalDocument $version): bool
    {
        // No recorded action at all: the affected-subject set already established that they hold an
        // older major, so silence binds.
        if (! $latest instanceof LegalConsent) {
            return true;
        }

        // They answered the notice in time — silence never bound them.
        if ($latest->action === ConsentAction::Objected || $latest->action === ConsentAction::Terminated) {
            return false;
        }
        // They already hold this version — typically an EXPRESS acceptance that landed after the
        // sweep took its ledger snapshot. The snapshot deliberately freezes the paged set, so this
        // live check is the only thing that can see it; without it a real, actively-given consent
        // would be recorded a second time as "bound by silence".
        if (! $latest->action->isAccepting()) {
            return true;
        }

        return $latest->document_major_version < $version->major_version;
    }
}
