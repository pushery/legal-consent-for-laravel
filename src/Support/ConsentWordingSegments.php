<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

/**
 * One acceptance sentence carrying SEVERAL document titles, cut into the runs that are links and
 * the runs that are not.
 *
 * ## Why this is not {@see ConsentWordingLink} called in a loop
 *
 * That class answers where ONE title sits and hands back the sentence in three pieces. Applied
 * again to one of its own pieces it starts cutting its own output, and the second title's `before`
 * is then a fragment rather than the sentence — the reassembly invariant holds per call and not
 * across calls. So a grouped sentence needs the positions of all titles found at once, against the
 * ORIGINAL wording, and cut in one pass.
 *
 * ## The same constraint, for the same reason
 *
 * THE SENTENCE IS NEVER REWRITTEN. `wording` is the snapshotted text the ledger records as the
 * thing that was agreed to, so every run comes out of it by offset and the concatenation of all
 * segments is the original byte for byte. An arm holds exactly that, because it is the property a
 * reader of the ledger depends on and the one a refactor would lose silently.
 *
 * The lookup is case-insensitive for the reason the single-title class measured: only the German
 * rows write the title the way the title reads, and an exact comparison links a handful and leaves
 * the rest quietly unlinked.
 *
 * ## Overlaps are decided, not left to chance
 *
 * Two titles can overlap — an imprint called `Impressum` and a second document called
 * `Impressum und Kontakt` share their opening. The longer match at a given position wins, and the
 * run it covers is then closed to the shorter one. Without a rule the outcome depends on the order
 * the caller happened to pass, which is the kind of thing that renders correctly on the screen it
 * was built against and differently on the next.
 *
 * ## A title that does not appear is not an error
 *
 * It is reported in {@see unlinked} and the caller renders it as a separate link beside the
 * sentence — the same fallback the single-title path takes, and for the same reason: a text that
 * cannot be reached at all is what breaks the clickwrap requirement (§ 305 Abs. 2 BGB).
 */
final readonly class ConsentWordingSegments
{
    /**
     * @param  list<array{text: string, document: ?array<string, mixed>}>  $segments  the sentence in
     *                                                                                order; `document`
     *                                                                                is null for a run
     *                                                                                that is just text
     * @param  list<array<string, mixed>>  $unlinked  members whose title does not appear in the sentence
     */
    private function __construct(
        public array $segments,
        public array $unlinked,
    ) {}

    /**
     * Cut $wording around the titles of $documents.
     *
     * @param  list<array<string, mixed>>  $documents  each carrying a `title`
     */
    public static function for(string $wording, array $documents): self
    {
        $found = [];
        $unlinked = [];

        foreach ($documents as $document) {
            $title = is_string($document['title'] ?? null) ? $document['title'] : '';

            // Offsets are collected against the ORIGINAL sentence, never against a remainder: a
            // remainder shifts every position after it and the second title lands in the wrong run.
            $at = $title === '' || $wording === '' ? false : mb_stripos($wording, $title);

            if ($at === false) {
                $unlinked[] = $document;

                continue;
            }

            $found[] = ['at' => $at, 'length' => mb_strlen($title), 'document' => $document];
        }

        // Earliest first, and at one position the LONGER match first, so the rule below closes the
        // run to the shorter one rather than to whichever the caller listed first.
        usort($found, static fn (array $a, array $b): int => [$a['at'], $b['length']] <=> [$b['at'], $a['length']]);

        $segments = [];
        $cursor = 0;

        foreach ($found as $match) {
            // Swallowed by a longer title that started earlier. Reported as unlinked rather than
            // dropped: it still has to be reachable, so the caller renders its separate link.
            if ($match['at'] < $cursor) {
                $unlinked[] = $match['document'];

                continue;
            }

            if ($match['at'] > $cursor) {
                $segments[] = ['text' => mb_substr($wording, $cursor, $match['at'] - $cursor), 'document' => null];
            }

            $segments[] = [
                // From the WORDING, not from the title: this is what keeps the sentence unaltered
                // where the two differ in case, which is most of the shipped rows.
                'text' => mb_substr($wording, $match['at'], $match['length']),
                'document' => $match['document'],
            ];

            $cursor = $match['at'] + $match['length'];
        }

        if ($cursor < mb_strlen($wording)) {
            $segments[] = ['text' => mb_substr($wording, $cursor), 'document' => null];
        }

        return new self($segments, $unlinked);
    }
}
