<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * Tells a subject a material change now requires their re-consent. Queued; channels come
 * from config (mail + database by default). The mail states the deadline AND its
 * consequence explicitly (§ 308 Nr. 5 lit. b BGB) and links to the consent route. Locale
 * follows the notifiable's preference (Laravel applies it when it implements
 * HasLocalePreference).
 */
final class ReconsentRequired extends Notification implements ShouldQueue
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
     * @param  array<string, string>  $replace
     */
    private function line(string $key, array $replace = []): string
    {
        // Branch the copy by legal basis so a privacy NOTICE is never framed as consent
        // ("zur Kenntnis nehmen", not "zustimmen") — the exact EDPB 05/2020 Rz. 122 error
        // this package exists to prevent.
        $basis = $this->document->type->legalBasis();
        $transKey = "legal-consent::notifications.{$basis}.{$key}";
        $translated = trans($transKey, $replace);

        if (is_string($translated) && $translated !== $transKey) {
            return $translated;
        }

        return $this->fallback($basis, $key);
    }

    private function fallback(string $basis, string $key): string
    {
        $acknowledgement = [
            'subject' => 'Wichtig: aktualisierte Datenschutzerklärung',
            'intro' => 'Wir haben unsere Datenschutzerklärung aktualisiert und bitten dich, die neue Fassung zur Kenntnis zu nehmen.',
            'cta' => 'Jetzt ansehen und zur Kenntnis nehmen',
            'consequence' => 'Bitte nimm die Aktualisierung rechtzeitig zur Kenntnis — andernfalls ist die weitere Nutzung ab dem Stichtag eingeschränkt.',
        ];

        $acceptance = [
            'subject' => 'Wichtig: aktualisierte Nutzungsbedingungen',
            'intro' => 'Wir haben unsere Nutzungsbedingungen aktualisiert und bitten dich um deine erneute Zustimmung.',
            'cta' => 'Jetzt ansehen und zustimmen',
            'consequence' => 'Bitte stimme rechtzeitig zu — andernfalls ist die weitere Nutzung ab dem Stichtag eingeschränkt.',
        ];

        return ($basis === 'acknowledgement' ? $acknowledgement : $acceptance)[$key] ?? '';
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
