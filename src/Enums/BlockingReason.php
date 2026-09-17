<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

/**
 * Why one locale is not ready to be released.
 *
 * THIS REPLACES SIX HARDCODED ENGLISH SENTENCES, and the reason is a consumer who could not
 * translate them. A support class that returns finished prose leaves an application
 * exactly one option: use the sentence itself as a translation key. That works until the package
 * rewords anything — at which point the lookup misses and the screen silently falls back to English,
 * on a compliance surface, with no test anywhere able to see it.
 *
 * The case is the contract; the wording is not.
 *
 * **The backing value is the original English sentence on purpose.** It keeps
 * `LegalReleaseNotReady`'s message, and anything logging it, byte-identical to before — so the only
 * thing that changed for a consumer is the TYPE, which their editor and static analysis point at,
 * rather than a string that shifts under them silently. A break you are told about is cheaper than
 * one you discover in production.
 */
enum BlockingReason: string
{
    /**
     * The document's text does not come from the draft store, so this screen cannot release it.
     *
     * Not a locale's problem but the document's, and it is listed against every locale anyway
     * because a caller asks "which locales blocked this" and an empty answer to a refused release
     * would read as a defect in the refusal.
     *
     * The two halves of a release read different texts: readiness is judged over the DRAFTS, and
     * the publisher reads whatever source the document is configured for. For a document on
     * `drafts` those are the same bytes. For any other they are not, and a reviewed draft would be
     * checked while a different text — one the reviewer never saw — is frozen as the evidence.
     */
    case NotDraftBacked = 'this document does not take its text from the draft store';

    case NoDraft = 'no draft has been written';

    case NotReviewed = 'not reviewed by a human yet';

    case StaleTranslation = 'the source text changed after this translation was reviewed';

    case NoChangeDescription = 'no change description has been written';

    case IncompleteChangeDescription = 'the change description has no headline or no impact statement';

    case StaleChangeDescription = 'the legal text changed after this change description was written';

    /**
     * The translation key for a screen a person reads.
     *
     * Written case by case rather than derived from the name. A derivation would hand a seventh
     * case a key nobody wrote a translation for, and the surface would render the key itself;
     * `match` without a default throws while the case is still being added, which is the only
     * moment the mistake is cheap.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotDraftBacked => 'legal-consent::ui.blocking_not_draft_backed',
            self::NoDraft => 'legal-consent::ui.blocking_no_draft',
            self::NotReviewed => 'legal-consent::ui.blocking_not_reviewed',
            self::StaleTranslation => 'legal-consent::ui.blocking_stale_translation',
            self::NoChangeDescription => 'legal-consent::ui.blocking_no_change_description',
            self::IncompleteChangeDescription => 'legal-consent::ui.blocking_incomplete_change_description',
            self::StaleChangeDescription => 'legal-consent::ui.blocking_stale_change_description',
        };
    }
}
