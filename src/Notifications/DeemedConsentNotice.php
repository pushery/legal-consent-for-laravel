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
 * The deemed-consent (Zustimmungsfiktion) notice: a minor/peripheral CONTRACT change where
 * silence counts as acceptance (NoticeMode::DeemedConsent). The `warning` line is the
 * § 308 Nr. 5 lit. b BGB special warning — that not objecting by the deadline is treated as
 * consent — which is a VALIDITY CONDITION of the fiction, not courtesy copy. It also states
 * the change, the objection deadline, and the free right to terminate before the effective
 * date. Deemed consent is lawful only for a contract (BGH XI ZR 26/20); a privacy notice and
 * a real consent never bind on silence, so this notice is contract-only.
 */
final class DeemedConsentNotice extends Notification implements SendsNoticeMail, ShouldQueue
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
        $objectBy = $this->document->objection_deadline;
        $effective = $this->document->enforce_from;

        $replace = [
            'title' => $this->document->title,
            'deadline' => $objectBy instanceof CarbonImmutable ? $objectBy->toDateString() : '',
            'effective' => $effective instanceof CarbonImmutable ? $effective->toDateString() : '',
        ];

        return (new MailMessage)
            ->subject($this->line('subject', $replace))
            ->line($this->line('intro', $replace))
            ->line($this->line('warning', $replace)) // § 308 Nr. 5 lit. b — silence = consent by :deadline
            ->action($this->line('cta', $replace), $this->consentUrl())
            ->line($this->line('termination', $replace));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'document_key' => $this->document->key,
            'version' => $this->document->version,
            'notice_mode' => $this->document->noticeMode()->value,
            'object_by' => $this->document->objection_deadline?->toIso8601String(),
            'effective_at' => $this->document->enforce_from?->toIso8601String(),
        ];
    }

    /**
     * The § 308 Nr. 5 lit. b warning is what makes the fiction valid, so the proof may only
     * certify this notice once that line actually rendered — an app that publishes an empty or
     * missing `notifications.deemed.warning` override ships a void notice, and the proof row
     * must say so rather than certify content that is not there.
     */
    public function mandatoryContentPresent(): bool
    {
        return $this->line('warning', ['deadline' => '', 'effective' => '', 'title' => '']) !== ''
            && $this->line('subject') !== '';
    }

    /**
     * Returns '' for a missing or empty translation (never the raw key): an untranslated token
     * in a legal notice is not content, and mandatoryContentPresent() must be able to see that.
     *
     * @param  array<string, string>  $replace
     */
    private function line(string $key, array $replace = []): string
    {
        $transKey = "legal-consent::notifications.deemed.{$key}";
        $translated = trans($transKey, $replace);

        return is_string($translated) && $translated !== $transKey ? $translated : '';
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
