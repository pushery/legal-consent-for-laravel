<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Notifications\ChangeNotification;
use Pushery\LegalConsent\Notifications\DeemedConsentNotice;
use Pushery\LegalConsent\Notifications\LegalChangeInformational;
use Pushery\LegalConsent\Notifications\ReconsentRequired;

/**
 * Reads the `notice_mail` block — the seams a developer activates on the change notices.
 *
 * TOP-LEVEL, and that is load-bearing rather than tidy: `mergeConfigFrom()` merges one level deep,
 * so a key added inside `notifications` would be ABSENT AT RUNTIME for every installation that has
 * published the config — not undocumented, gone, with no message anywhere. The comment saying so
 * lives in the config file too, because the next feature will want to put its key there.
 *
 * Every seam here is inert by default. That is not politeness: the notice body is hashed into an
 * append-only proof row, so a value that changed the mail without being asked for would move the
 * bytes of a document nobody can correct afterwards.
 */
final readonly class NoticeMailConfig
{
    /** The shipped notification per notice mode — the fallback for a misconfigured override. */
    private const array SHIPPED = [
        'active_reconsent' => ReconsentRequired::class,
        'deemed_consent' => DeemedConsentNotice::class,
        'info_push' => LegalChangeInformational::class,
    ];

    /**
     * The Markdown view the notice renders through, or null to keep Laravel's global template.
     *
     * ⚠️ It is a MARKDOWN view, and swapping in a plain one is not a styling choice. `->view()`
     * nulls `$markdown` and leaves `introLines`/`outroLines` empty, so the proof body would
     * collapse to the subject line while `mandatory_content_ok` kept reporting true — a row
     * certifying content that is not in it. The base notification only ever calls `->markdown()`.
     */
    public static function view(): ?string
    {
        return self::string('view');
    }

    /** The mail theme. A name resolves against Laravel's themes; a `::`-namespaced one is a view. */
    public static function theme(): ?string
    {
        return self::string('theme');
    }

    /** @return array{0: string, 1: string|null}|null */
    public static function from(): ?array
    {
        $from = config('legal-consent.notice_mail.from');
        $from = is_array($from) ? $from : [];

        $address = $from['address'] ?? null;
        $name = $from['name'] ?? null;

        return is_string($address) && trim($address) !== ''
            ? [$address, is_string($name) && trim($name) !== '' ? $name : null]
            : null;
    }

    public static function replyTo(): ?string
    {
        return self::string('reply_to');
    }

    /** Prepended to every notice subject, e.g. a brand tag. Null keeps the subject as written. */
    public static function subjectPrefix(): ?string
    {
        return self::string('subject_prefix');
    }

    /**
     * Whether the effective date is appended to the subject.
     *
     * Off by default, and worth saying why: the subject is the first line of the proof body, so
     * turning this on changes the hash of every notice written from then on. That is fine — it is
     * a decision — but it must be one somebody made.
     */
    public static function showsEffectiveDateInSubject(): bool
    {
        return config('legal-consent.notice_mail.subject_effective_date', false) === true;
    }

    /**
     * The notification class for a mode: a configured override when it is usable, the shipped
     * class otherwise.
     *
     * `is_a(..., allow_string: true)` rather than instantiating and hoping: this runs inside a
     * queued sweep, and a class-string that is missing or is not a change notification would
     * otherwise take down the whole run with a TypeError somewhere downstream — after some
     * subjects were already notified and their proof rows written. A wrong setting falls back
     * loudly in the config sense (the shipped class is used, and nothing pretends otherwise) and
     * quietly in the operational one, which is the correct way round for an append-only ledger.
     *
     * The bar is {@see ChangeNotification}, not "any notification": that is where the document
     * constructor, the shared envelope and the proof-safe `->markdown()` live, so a class outside
     * it could not be constructed here and would not honor the invariants anyway.
     */
    public static function notificationFor(NoticeMode $mode, LegalDocument $document): ChangeNotification
    {
        $shipped = self::SHIPPED[$mode->value] ?? LegalChangeInformational::class;
        $configured = self::string("notification.{$mode->value}");

        $class = $configured !== null && is_a($configured, ChangeNotification::class, allow_string: true)
            ? $configured
            : $shipped;

        return new $class($document);
    }

    private static function string(string $key): ?string
    {
        $value = config("legal-consent.notice_mail.{$key}");

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
