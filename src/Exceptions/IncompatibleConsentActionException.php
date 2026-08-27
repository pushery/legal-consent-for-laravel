<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Enums\DocumentType;
use RuntimeException;

/**
 * An action was recorded against a document type it cannot legally describe.
 *
 * Every NAMED transition on the manager checks this for its own action and says so in its own
 * words — {@see NotWithdrawableException}, {@see NotObjectableException},
 * {@see NotTerminableException}, {@see NotGrantableException}. This is the same rule for the
 * general door, `record()`, which takes an arbitrary action from an arbitrary caller and is
 * reachable from a consuming application's own model through `HasLegalConsents::recordConsent()`.
 *
 * It matters because the ledger is append-only. A row saying a subject objected to a consent, was
 * deemed to have consented by silence, or granted a privacy notice asserts a state that does not
 * legally exist — and it can never be corrected, only read, by everyone who comes after.
 */
final class IncompatibleConsentActionException extends RuntimeException
{
    public static function for(string $documentKey, DocumentType $type, ConsentAction $action): self
    {
        return new self(sprintf(
            "Action '%s' cannot be recorded against '%s' (%s) — %s The ledger is append-only, so a row asserting a state that does not legally exist can never be corrected.",
            $action->value,
            $documentKey,
            $type->value,
            self::reason($action),
        ));
    }

    /**
     * Why the pairing is refused, per action.
     *
     * `re_accepted` and `parental` have no arm because they reach no refusal: re-acceptance after a
     * material change and a guardian's consent both make sense for every type that binds anyone,
     * and the one type that binds nobody is already refused, earlier and by name, as not
     * consent-bearing. The default arm covers a case that is added to the enum without being added
     * to the matrix.
     */
    private static function reason(ConsentAction $action): string
    {
        return match ($action) {
            ConsentAction::Granted => 'a consent is granted only where one is asked for (Art. 6(1)(a)); a contract is accepted and a privacy notice is taken notice of, never "ich willige ein" (EDPB 05/2020 Rz. 122).',
            ConsentAction::Acknowledged => 'taking notice is how a mandatory document is accepted (Art. 13/14, § 305 II BGB); a voluntary consent needs an unambiguous affirmative act (Art. 4(11)), which taking notice is not.',
            ConsentAction::Withdrawn, ConsentAction::Declined => 'only a real consent can be withdrawn or declined (Art. 7(3)); a contract ends by cancellation and a privacy notice is information.',
            ConsentAction::Objected => 'an objection rebuts a change deemed accepted by silence (§ 308 Nr. 5 lit. a BGB) or processing on legitimate interest (Art. 21); Art. 21 does not reach consent-based processing, where the instrument is withdrawal.',
            ConsentAction::Terminated => 'the free right to terminate before a change takes effect exists against a contract (§ 675g / § 327r Abs. 3 BGB, P2B), and nothing else here is one.',
            ConsentAction::DeemedAccepted => 'silence is deemed acceptance only for a contract change (§ 308 Nr. 5 BGB; BGH XI ZR 26/20); a real consent can never be deemed (EDPB 05/2020 Rz. 79).',
            ConsentAction::OptInRequested, ConsentAction::Confirmed => 'a double opt-in belongs to a voluntary consent (§ 7 Abs. 2 UWG with Art. 7 DSGVO); a mandatory document is accepted where its full text is presented, not by following a link.',
            default => 'the action describes something this document type cannot do.',
        };
    }
}
