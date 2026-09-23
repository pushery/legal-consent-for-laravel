<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

use Pushery\LegalConsent\Exceptions\LegalPublishRefused;

/**
 * Why the publisher refused a version, for each refusal an operator can meet on the admin screens.
 *
 * A refusal's message is an English sentence for a log or a command line, and it names the document
 * by its configuration key. The editor and the overview used to put that sentence into their own
 * translated status line whole, so a German screen showed an English sentence about `'terms'` inside
 * a German one, next to lines that name the same document through `NamesLegalTexts`. A reason and the
 * values it was built from let a screen word the refusal itself ({@see LegalPublishRefused}).
 *
 * ONLY THE REFUSALS A SCREEN CAN REACH HAVE A CASE HERE, and the others are left out on purpose. The
 * overview releases in the mode the document type takes, the editor offers a deemed release only where
 * the type allows one and names every empty field before the publisher is asked, and both release the
 * configured languages. So a refusal of the mode for the type, of a missing objection deadline, of a
 * regime or a language the configuration does not know, or of a regime on an editorial change can only
 * come from the command line or a crafted request, and it keeps its English sentence.
 */
enum PublishRefusal: string
{
    /** The same text under the same version, asked for in another notice mode. */
    case VersionTakenInAnotherMode = 'version_taken_in_another_mode';

    /** A document whose registry entry turned informational while its active version still binds. */
    case BindingDocumentBecameInformational = 'binding_document_became_informational';

    /** A version below the one already active, which would re-activate an older text. */
    case VersionLowerThanActive = 'version_lower_than_active';

    /** A major that another language already carries under a different notice mode. */
    case ModeDiffersAcrossLanguages = 'mode_differs_across_languages';

    /** A major raised in a mode that does not ask anybody again. */
    case MajorNeedsReconsent = 'major_needs_reconsent';

    /** An active re-consent on a version that keeps the major, which gates nobody. */
    case GatingModeKeepsTheMajor = 'gating_mode_keeps_the_major';

    /** An objection deadline on or after the day the change takes effect. */
    case ObjectionDeadlineNotBeforeEffectiveDate = 'objection_deadline_not_before_effective_date';

    /**
     * The translation key of the sentence that words this refusal for a person.
     *
     * The sentence continues a status line that has already named the document, so it does not name
     * it again. Its placeholders are the refusal's values, with a language given as its code.
     */
    public function label(): string
    {
        return 'legal-consent::ui.publish_refused_'.$this->value;
    }
}
