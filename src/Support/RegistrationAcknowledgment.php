<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Which documents may be acknowledged, and which of them the registration flow writes.
 *
 * TWO QUESTIONS, AND THE CLASS NAME DESCRIBES THE OLDER ONE. It began as the registration
 * answer alone; {@see covers()} now answers the wider one — may this page be acknowledged at all,
 * from anywhere — because that is the question {@see DefaultConsentManager::acknowledge()} actually
 * asks. The name stays: it is a published class, and renaming it would break every consumer that
 * references it for a word. Which question a method answers is on the method.
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
 * ## …and the page that is never on that form
 *
 * A confirmation shown inside a CHECKOUT is the same kind of record and belongs nowhere near
 * sign-up. It says so with the general pair, and the registration flow leaves it alone:
 *
 *     'creator-supplies-the-service' => [
 *         'legal_basis' => 'informational',
 *         'acknowledgeable' => true,
 *         'acknowledgment_wording' => 'I understand the creator provides this service.',
 *     ],
 *
 * `acknowledge_at_registration` implies `acknowledgeable`, so an existing registry keeps working
 * word for word. `registration_wording` wins where both wordings are set — it is the more specific,
 * and a registry carrying both is an operator whose sign-up form words the page its own way.
 *
 * Refusing HERE rather than where the row is written is the difference between a configuration that
 * does nothing and a registration that throws. A half-configured document lands in the first
 * category, where an operator can find it, instead of the second, where their users do.
 *
 * ## What it deliberately does NOT change
 *
 * THE DOCUMENT TYPE STAYS `informational`, AND THAT IS THE POINT RATHER THAN A SHORTCUT. Moving
 * such a page to `acknowledgement` is a different decision, not a stronger version of this one: an
 * acknowledgment GATES, so a new version stops every reader until they take notice. Where that is
 * what an operator wants, `'locale_fallback' => true` on the entry keeps an imprint published only
 * in the source language reachable under every other locale's URL ({@see SourceLanguageFallback});
 * without it, a release has to cover every configured locale.
 *
 * AND IT NEVER BLOCKS. {@see ConsentGate} keeps asking `isConsentBearing()` and is not routed
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
        return $document->type->isConsentBearing() || self::coversRegistration($document->key);
    }

    /**
     * May this key be acknowledged AT ALL — anywhere, not only at registration?
     *
     * ONE FLAG USED TO ASSERT TWO DIFFERENT FACTS, AND THAT IS WHAT THIS SPLIT REPAIRS.
     * `acknowledge_at_registration` said both "this page may be written to the ledger" and "the
     * registration flow writes it", and {@see DefaultConsentManager::acknowledge()} — which is
     * callable from anywhere — asked the combined question. So a consumer who shows a page inside a
     * CHECKOUT, and must not touch the registration flow at all, had one way through: set a flag
     * whose name says registration. A configuration that states something untrue to unlock a
     * correct behavior is the same failure this package refuses one layer up, in the ledger itself.
     *
     * They really are two facts. A page shown before every purchase is acknowledgeable and must NOT
     * be written at sign-up; a page on the registration form is both. So `acknowledgeable` carries
     * the general permission, `acknowledge_at_registration` keeps meaning what it says — and it
     * still implies the first, which is what leaves every existing registry untouched.
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
        $entry = self::entry($key);

        if ($entry === null) {
            return false;
        }

        $flagged = ($entry['acknowledgeable'] ?? false) === true
            || ($entry['acknowledge_at_registration'] ?? false) === true;

        return $flagged && self::wordingFor($key) !== null;
    }

    /**
     * Does the REGISTRATION flow write this key, specifically?
     *
     * The narrower of the two questions, and the one the flag was named for. A page flagged only
     * `acknowledgeable` is reachable through {@see DefaultConsentManager::acknowledge()} and is
     * deliberately invisible here: its moment is somewhere else in the application, and writing it
     * at sign-up would record a confirmation nobody gave yet.
     */
    public static function coversRegistration(string $key): bool
    {
        $entry = self::entry($key);

        if ($entry === null) {
            return false;
        }

        return ($entry['acknowledge_at_registration'] ?? false) === true
            && self::wordingFor($key) !== null;
    }

    /**
     * One document's registry entry, or null when the key names none.
     *
     * MOVED to {@see DocumentRegistryEntry}, which {@see RegistrationField} reads as well. This
     * method stays as the thin call its four callers here already make, so nothing about them
     * changes; the reasoning about narrowing a `mixed` config value lives with the reader now.
     *
     * @return array<string, mixed>|null
     */
    private static function entry(string $key): ?array
    {
        return DocumentRegistryEntry::for($key);
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
        $entry = self::entry($key);

        if ($entry === null) {
            return null;
        }

        // `registration_wording` first, because it is the more specific of the two and a registry
        // that carries both is saying the registration form words this page its own way. The
        // general key exists for the page that is never on that form at all, where the older name
        // would be a sentence claiming a screen the reader never saw.
        $wording = $entry['registration_wording'] ?? $entry['acknowledgment_wording'] ?? null;

        if (! is_string($wording) || trim($wording) === '') {
            return null;
        }

        $wording = trim($wording);

        // THROUGH THE TRANSLATOR, because a configuration cannot be translated per reader and this
        // sentence is read by one. `config:cache` freezes the language the cache was built in, so a
        // registry shipping seven of them had a German registrant tick a German sentence while the
        // ledger stored the English one — a proof of the wrong text, on the record that exists to
        // demonstrate what somebody agreed to.
        //
        // `__()` hands back its own argument when nothing is registered under it, so the literal
        // sentence every existing registry carries passes through untouched, and both a dotted key
        // and a JSON-translated sentence resolve. A translation that resolves to nothing falls back
        // rather than writing an empty sentence, which is the state the check above refuses.
        $translated = __($wording);

        return is_string($translated) && trim($translated) !== '' ? trim($translated) : $wording;
    }
}
