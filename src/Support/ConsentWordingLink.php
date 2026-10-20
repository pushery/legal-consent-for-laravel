<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * Where a document's title sits inside the acceptance sentence, so the name itself can be the link.
 *
 * The sentence is never rewritten, and that is the whole constraint this class exists under.
 * `wording` is the snapshotted text the ledger records as the thing that was agreed to; it is frozen
 * proof. So the linked run comes out of the wording by offset — never by substituting the title —
 * and {@see before} . {@see match} . {@see after} reassembles the original byte for byte.
 *
 * **The lookup is case-insensitive because an exact one would link almost nothing.** Of the
 * shipped acceptance sentences (seven locales, three consent document types), only the German
 * ones write the title the way the title reads. The rest lower-case it mid-sentence: `Terms of
 * Use` as the title against *"I accept the terms of use."* in the wording. An exact comparison
 * would link a handful and leave the rest silently unlinked.
 *
 * A null return is not an error and must not be treated as one. The title genuinely may not appear
 * in the sentence — `die AGB` against `Allgemeine Geschäftsbedingungen` — and then there is nothing
 * to anchor. The caller falls back to the separate link, because a text that cannot be reached at
 * all is the one outcome that breaks the clickwrap requirement (§ 305 Abs. 2 BGB): consent binds
 * only if the full text was available before agreeing.
 */
final readonly class ConsentWordingLink
{
    private function __construct(
        /** The wording up to the title, verbatim. */
        public string $before,
        /** The title as the sentence spells it — taken from the wording, never from the title. */
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
        // The empty wording needs no check of its own: mb_stripos() finds no title in an empty
        // sentence, and the lookup below answers null for it. The empty title is not, because it
        // matches at offset 0 and would link nothing.
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
            // From the wording, not the title: this is what keeps the sentence unaltered when the
            // two differ in case, which is every shipped locale but German.
            mb_substr($wording, $at, $length),
            mb_substr($wording, $at + $length),
        );
    }
}
