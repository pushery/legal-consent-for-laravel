<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Whether a document gets a ledger row written for it at registration.
 *
 * ONE place, because the answer is needed in four and a rule spread over four call sites is a rule
 * that drifts. Before this, every one of them asked `$document->type->isConsentBearing()` directly,
 * and the informational answer was the same everywhere by accident rather than by construction.
 *
 * ## Why an informational page may now be in the set at all
 *
 * An informational page binds nobody — that is the whole meaning of the basis, and it is why
 * {@see DocumentType::isConsentBearing()} excludes it. But an
 * application may still SHOW one at sign-up: a single checkbox naming a privacy policy, an imprint,
 * a cookie notice and an accessibility statement together is an ordinary registration form, and the
 * operator who built it wants to know, per page, which version each person was shown. Skipping the
 * informational ones leaves a ticked box with a ledger that mentions one of the four.
 *
 * So the operator says so per document, in the registry:
 *
 *     'imprint' => [
 *         'source' => 'markdown',
 *         'legal_basis' => 'informational',
 *         'acknowledge_at_registration' => true,
 *         'registration_wording' => 'I have read the imprint.',
 *     ],
 *
 * BOTH keys, and a document carrying only the flag is simply NOT covered rather than an error at
 * registration time. The wording is the sentence the operator's own form puts next to the page, and
 * the ledger stores it verbatim — an informational document has none of its own, because this
 * package never asks the reader for anything on such a page. Without it there is nothing truthful
 * to write down, and inventing one is the failure this whole design avoids.
 *
 * Refusing HERE rather than where the row is written is the difference between a configuration that
 * does nothing and a registration that throws. A half-configured document lands in the first
 * category, where an operator can find it, instead of the second, where their users do.
 *
 * ## What it deliberately does NOT change
 *
 * ⚠️ THE DOCUMENT TYPE STAYS `informational`, AND THAT IS THE POINT RATHER THAN A SHORTCUT. Moving
 * such a page to `acknowledgement` is the obvious alternative and it is the wrong one: the
 * source-locale fallback in {@see PublishedDocumentReader} is constrained to informational rows, so
 * an imprint published only in the source language would stop being reachable under every other
 * locale's URL, and a release would suddenly have to cover every configured locale.
 *
 * ⚠️ AND IT NEVER BLOCKS. {@see ConsentGate} keeps asking `isConsentBearing()` and is not routed
 * through here, so a flagged page can go stale without locking anybody out of anything. A page that
 * binds nobody must not become a gate by being written down, and pressure would be wrong there.
 * What the operator gets is the STATE — which version was acknowledged, and whether it is current —
 * and a way to write a fresh row. How they ask for a second look (a banner, a notice, not at all)
 * is theirs to decide.
 */
final readonly class RegistrationAcknowledgment
{
    /**
     * Is this document written to the ledger when a subject registers?
     *
     * True for every consent-bearing document, as it always was, plus an informational page the
     * operator flagged. The two halves are deliberately one question: a call site that asked only
     * the second would forget the first.
     */
    public static function isRecordedAtRegistration(LegalDocument $document): bool
    {
        return $document->type->isConsentBearing() || self::covers($document->key);
    }

    /**
     * Has the operator flagged this key for acknowledgment at registration?
     *
     * Reads the registry rather than the row, so it answers for a key whose document is not
     * resolved yet — which is what the validation rules need, one step before a row exists.
     *
     * Strictly `true`, never truthy: a key configured as `'yes'` or `1` is an operator typing
     * something this package does not define, and guessing what they meant is how a page ends up in
     * a ledger nobody asked to write to.
     */
    public static function covers(string $key): bool
    {
        $documents = config('legal-consent.documents', []);

        if (! is_array($documents) || ! isset($documents[$key]) || ! is_array($documents[$key])) {
            return false;
        }

        if (($documents[$key]['acknowledge_at_registration'] ?? false) !== true) {
            return false;
        }

        return self::wordingFor($key) !== null;
    }

    /**
     * The sentence the operator's form shows next to this page, or null when there is none.
     *
     * Trimmed and emptiness-checked, because a blank string is an operator who started configuring
     * and stopped. Writing it would put an empty acceptance sentence in the ledger, which reads
     * exactly like a page nobody was shown a control for — the one state this feature exists to
     * tell apart from a page they were.
     */
    public static function wordingFor(string $key): ?string
    {
        $documents = config('legal-consent.documents', []);

        if (! is_array($documents) || ! isset($documents[$key]) || ! is_array($documents[$key])) {
            return null;
        }

        $wording = $documents[$key]['registration_wording'] ?? null;

        if (! is_string($wording) || trim($wording) === '') {
            return null;
        }

        return trim($wording);
    }
}
