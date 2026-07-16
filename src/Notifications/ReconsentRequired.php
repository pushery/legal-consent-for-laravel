<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Pushery\LegalConsent\Contracts\SendsNoticeMail;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * The ACTIVE re-consent notification: a material CONTRACT change requires the subject to
 * actively agree before it applies (NoticeMode::ActiveReconsent). Reserved for contracts —
 * a privacy notice is info-only and never re-consented (it goes out as
 * LegalChangeInformational). Queued; channels come from config (mail + database by default).
 * The mail states the deadline AND its consequence explicitly (§ 308 Nr. 5 lit. b BGB) and
 * links to the consent route. Locale follows the notifiable's preference.
 */
final class ReconsentRequired extends Notification implements SendsNoticeMail, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LegalDocument $document) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = config('legal-consent.notifications.channels', ['mail', 'database']);

        if (is_array($channels)) {
            $strings = array_values(array_filter($channels, is_string(...)));

            if ($strings !== []) {
                return $strings;
            }
        }

        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $enforceAt = $this->document->enforce_from;
        $deadline = $enforceAt instanceof CarbonImmutable ? $enforceAt->toDateString() : '';

        return (new MailMessage)
            ->subject($this->line('subject', ['title' => $this->document->title]))
            ->line($this->line('intro', ['title' => $this->document->title]))
            ->action($this->line('cta'), $this->consentUrl())
            ->line($this->line('consequence', ['deadline' => $deadline]));
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
     * A re-consent notice must state the deadline AND its consequence (§ 308 Nr. 5 lit. b BGB);
     * the hardcoded fallback guarantees both even with no translation, so this is a real check
     * of the rendered content rather than an assumption.
     */
    public function mandatoryContentPresent(): bool
    {
        return $this->line('consequence', ['deadline' => '']) !== ''
            && $this->line('subject', ['title' => '']) !== '';
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function line(string $key, array $replace = []): string
    {
        $transKey = "legal-consent::notifications.contract.{$key}";
        $translated = trans($transKey, $replace);

        if (is_string($translated) && $translated !== $transKey) {
            return $translated;
        }

        return $this->fallback($key);
    }

    private function fallback(string $key): string
    {
        return [
            'subject' => 'Wichtig: aktualisierte Nutzungsbedingungen',
            'intro' => 'Wir haben unsere Nutzungsbedingungen aktualisiert und bitten dich um deine erneute Zustimmung.',
            'cta' => 'Jetzt ansehen und zustimmen',
            'consequence' => 'Bitte stimme rechtzeitig zu — andernfalls ist die weitere Nutzung ab dem Stichtag eingeschränkt.',
        ][$key] ?? '';
    }

    private function consentUrl(): string
    {
        $name = config('legal-consent.routes.consent_name');

        if (is_string($name) && $name !== '' && Route::has($name)) {
            return route($name);
        }

        $path = config('legal-consent.routes.consent_path', '/legal-consent');

        return url(is_string($path) ? $path : '/legal-consent');
    }
}
