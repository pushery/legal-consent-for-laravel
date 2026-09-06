<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * Where a document's title sits inside the acceptance sentence, so the NAME itself can be the link.
 *
 * ⚠️ THE SENTENCE IS NEVER REWRITTEN, and that is the whole constraint this class exists under.
 * `wording` is the snapshotted text the ledger records as the thing that was agreed to; it is frozen
 * proof. So the linked run comes out of the WORDING by offset — never by substituting the title —
 * and {@see before} . {@see match} . {@see after} reassembles the original byte for byte.
 *
 * **The lookup is case-insensitive because an exact one would have linked almost nothing.** Measured
 * across the twenty-one published rows (seven locales, three consent document types): only the
 * German ones write the title the way the title reads. The rest lower-case it mid-sentence —
 * `Terms of Use` as the title against *"I accept the terms of use."* in the wording. An exact
 * comparison links a handful and leaves the rest silently unlinked, which is the failure shape this
 * package keeps meeting: correct for the case someone tested, quietly wrong for the rest.
 *
 * ⚠️ THAT COUNT SAID "fourteen … two documents" AND WAS A SENTENCE, NOT A MEASUREMENT. The third
 * consent type had never been in it, and that is where the one real gap sat: `es/newsletter`
 * carried the heading `Boletín` against a sentence saying `la newsletter`, so twenty of the
 * twenty-one pairs anchored and one silently fell back to the separate-link layout. The shipped
 * language files are now walked on every run, so the number is measured rather than quoted.
 *
 * A null return is not an error and must not be treated as one. The title genuinely may not appear
 * in the sentence — `die AGB` against `Allgemeine Geschäftsbedingungen` — and then there is nothing
 * to anchor. The caller falls back to the separate link, because a text that cannot be reached at
 * all is the one outcome that breaks the clickwrap requirement (§ 305 Abs. 2 BGB): consent binds
 * only if the full text was available BEFORE agreeing.
 */
final readonly class ConsentWordingLink
{
    private function __construct(
        /** The wording up to the title, verbatim. */
        public string $before,
        /** The title AS THE SENTENCE SPELLS IT — taken from the wording, never from the title. */
        public string $match,
        /** The wording after the title, verbatim. */
        public string $after,
    ) {}

    /**
     * Locate the title inside the wording, or null when it does not appear.
     *
     * Multibyte throughout: a byte-offset `substr` would cut a German umlaut or a Portuguese
     * cedilla in half and emit invalid UTF-8 into a legal sentence.
     */
    public static function locate(string $wording, string $title): ?self
    {
        if ($title === '' || $wording === '') {
            return null;
        }

        $at = mb_stripos($wording, $title);

        if ($at === false) {
            return null;
        }

        $length = mb_strlen($title);

        return new self(
            mb_substr($wording, 0, $at),
            // From the WORDING, not the title: this is what keeps the sentence unaltered when the
            // two differ in case, which is twelve of the fourteen measured rows.
            mb_substr($wording, $at, $length),
            mb_substr($wording, $at + $length),
        );
    }
}
