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
use Pushery\LegalConsent\Enums\DocumentType;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * The INFO-ONLY change notification (NoticeMode::InfoPush): the subject is actively informed
 * on a durable medium that a legal text changed, but NO action is required — it takes effect
 * regardless. This is the § 675g / Finom case ("keine Aktion erforderlich") and the material
 * privacy-notice update (Art. 13/14, WP260 rev.01). It NEVER threatens restriction and never
 * frames a privacy notice as consent (EDPB 05/2020 Rz. 122): a notice is acknowledged, and
 * where a right exists it states the objection (Art. 21) or free-termination right instead.
 */
final class LegalChangeInformational extends Notification implements SendsNoticeMail, ShouldQueue
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

        $mail = (new MailMessage)
            ->subject($this->line('subject', ['title' => $this->document->title]))
            ->line($this->line('intro', ['title' => $this->document->title]))
            ->action($this->line('cta'), $this->reviewUrl())
            ->line($this->line('effective', ['deadline' => $deadline]));

        // A privacy notice always carries the Art. 21 right to object; a contract carries a
        // free-termination line only when one was offered (§ 675g / § 327r). A pure
        // reference-rate info change offers neither, so no such line is shown.
        if ($this->rightApplies()) {
            $mail->line($this->line('objection', ['deadline' => $deadline]));
        }

        return $mail;
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
            'effective_at' => $this->document->enforce_from?->toIso8601String(),
            'action_required' => false,
        ];
    }

    private function rightApplies(): bool
    {
        if ($this->basis() === 'acknowledgement') {
            return true;
        }

        return $this->document->offers_termination;
    }

    /**
     * A privacy notice uses the acknowledgement wording (zur Kenntnis nehmen, never zustimmen);
     * a contract (or any other type routed here) uses the contract wording.
     */
    private function basis(): string
    {
        return $this->document->type === DocumentType::PrivacyNotice ? 'acknowledgement' : 'contract';
    }

    /**
     * An info-only notice must state WHAT changed and WHEN it takes effect (the mandatory
     * content of an actively-pushed change notice); the proof row certifies only that.
     */
    public function mandatoryContentPresent(): bool
    {
        return $this->line('intro', ['title' => '']) !== ''
            && $this->line('effective', ['deadline' => '']) !== ''
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
        $transKey = "legal-consent::notifications.informational.{$this->basis()}.{$key}";
        $translated = trans($transKey, $replace);

        return is_string($translated) && $translated !== $transKey ? $translated : '';
    }

    private function reviewUrl(): string
    {
        $name = config('legal-consent.routes.consent_name');

        if (is_string($name) && $name !== '' && Route::has($name)) {
            return route($name);
        }

        $path = config('legal-consent.routes.consent_path', '/legal-consent');

        return url(is_string($path) ? $path : '/legal-consent');
    }
}
