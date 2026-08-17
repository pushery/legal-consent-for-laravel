<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The ACTIVE re-consent notification: a material CONTRACT change requires the subject to
 * actively agree before it applies (NoticeMode::ActiveReconsent). Reserved for contracts —
 * a privacy notice is info-only and never re-consented (it goes out as
 * LegalChangeInformational). Queued; channels come from config (mail + database by default).
 * The mail states the deadline AND its consequence explicitly (§ 308 Nr. 5 lit. b BGB) and
 * links to the consent route.
 *
 * The locale is PINNED by the dispatch sweep onto the notification itself, and that pin wins: it
 * is the version's locale, which is also the locale the durable-medium proof row is rendered in.
 * A notifiable preference would break that congruence — the subject would receive one language
 * while an append-only row certified another.
 */
class ReconsentRequired extends ChangeNotification
{
    protected function translationGroup(): string
    {
        return 'contract';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $enforceAt = $this->document->enforce_from;
        $deadline = $enforceAt instanceof CarbonImmutable ? $enforceAt->toDateString() : '';

        $mail = (new MailMessage)
            ->subject($this->line('subject', ['title' => $this->document->title]))
            ->line($this->line('intro', ['title' => $this->document->title]));

        // Before the CTA: this notice asks for agreement, and asking before saying what changed is
        // the form § 305 Abs. 2 BGB exists to prevent.
        $this->addChangeItems($mail, $this->document);

        $mail->action($this->line('cta'), $this->ctaUrl())
            ->line($this->line($this->consequenceKey(), ['deadline' => $deadline]));

        return $this->envelope($mail);
    }

    /**
     * § 308 Nr. 5 lit. a BGB wants an "angemessene Frist", § 327r Abs. 2 S. 2 Nr. 1 BGB the
     * "Zeitpunkt der Änderung" — so the consequence line names the date rather than gesturing at
     * one. A version with no enforcement date has no date to name, and a sentence with a gap where
     * the date belongs reads worse than one that never promised a date, so that case keeps its own
     * undated wording instead of rendering a blank.
     */
    private function consequenceKey(): string
    {
        return $this->document->enforce_from instanceof CarbonImmutable
            ? 'consequence'
            : 'consequence_undated';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'document_key' => $this->document->key,
            'version' => $this->document->version,
            'effective_at' => $this->document->enforce_from?->toIso8601String(),
        ];
    }

    /**
     * A re-consent notice must state the deadline AND its consequence (§ 308 Nr. 5 lit. b BGB).
     *
     * This asks the TRANSLATOR, never {@see line}. line() falls back to hardcoded German for
     * exactly these keys, so a check routed through it could not return false under any input —
     * and its answer is written immutably into a proof row. A certification that cannot fail
     * certifies nothing, and it is worse than none: someone producing that row in a proceeding
     * invites the question of what else it never measured.
     *
     * The fallback stays where it belongs, on the display path: a subject who receives the notice
     * in the wrong language is better served than one who receives an empty mail.
     */
    public function mandatoryContentPresent(): bool
    {
        return $this->translated($this->consequenceKey()) && $this->translated('subject');
    }

    /**
     * Whether a mandatory key genuinely resolves for the current locale — a missing key, and an
     * override published as an empty string, both mean the line is not there.
     */
    private function translated(string $key): bool
    {
        $transKey = "legal-consent::notifications.contract.{$key}";
        $translated = trans($transKey);

        return is_string($translated) && $translated !== $transKey && trim($translated) !== '';
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function line(string $key, array $replace = []): string
    {
        $translated = $this->translate($key, $replace);

        return $translated !== '' ? $translated : $this->fallback($key, $replace);
    }

    /**
     * The fallback is raw text, so it has to interpolate for itself — trans() is what normally
     * substitutes the placeholders, and it is precisely the call that did not resolve here. Without
     * this the untranslated path would mail a literal ":deadline" to a subject.
     *
     * @param  array<string, string>  $replace
     */
    private function fallback(string $key, array $replace = []): string
    {
        $line = [
            'subject' => 'Wichtig: aktualisierte Nutzungsbedingungen',
            'intro' => 'Wir haben unsere Nutzungsbedingungen aktualisiert und bitten dich um deine erneute Zustimmung.',
            'cta' => 'Jetzt ansehen und zustimmen',
            'consequence' => 'Bitte stimme bis zum :deadline zu — andernfalls ist die weitere Nutzung ab diesem Tag eingeschränkt.',
            'consequence_undated' => 'Bitte stimme rechtzeitig zu — andernfalls ist die weitere Nutzung ab dem Stichtag eingeschränkt.',
        ][$key] ?? '';

        $substitutions = [];

        foreach ($replace as $placeholder => $value) {
            $substitutions[':'.$placeholder] = $value;
        }

        return strtr($line, $substitutions);
    }
}
