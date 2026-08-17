<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Pushery\LegalConsent\Enums\DocumentType;

/**
 * The INFO-ONLY change notification (NoticeMode::InfoPush): the subject is actively informed
 * on a durable medium that a legal text changed, but NO action is required — it takes effect
 * regardless. This is the § 675g / Finom case ("keine Aktion erforderlich") and the material
 * privacy-notice update (Art. 13/14, WP260 rev.01). It NEVER threatens restriction and never
 * frames a privacy notice as consent (EDPB 05/2020 Rz. 122): a notice is acknowledged, and
 * where a right exists it states the objection (Art. 21) or free-termination right instead.
 */
class LegalChangeInformational extends ChangeNotification
{
    protected function translationGroup(): string
    {
        return "informational.{$this->basis()}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $enforceAt = $this->document->enforce_from;
        $deadline = $enforceAt instanceof CarbonImmutable ? $enforceAt->toDateString() : '';

        $mail = (new MailMessage)
            ->subject($this->line('subject', ['title' => $this->document->title]))
            ->line($this->line('intro', ['title' => $this->document->title]));

        // WHAT changed, before the call to action — a reader decides whether to click on the
        // strength of the delta, not the other way round.
        $this->addChangeItems($mail, $this->document);

        $mail->action($this->line('cta'), $this->ctaUrl())
            ->line($this->line('effective', ['deadline' => $deadline]));

        // A privacy notice always carries the Art. 21 right to object; a contract carries a
        // free-termination line only when one was offered (§ 675g / § 327r). A pure
        // reference-rate info change offers neither, so no such line is shown.
        if ($this->rightApplies()) {
            $mail->line($this->line('objection', ['deadline' => $deadline]));
        }

        return $this->envelope($mail);
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
     * An info-only notice must state WHEN the change takes effect, and must reach the subject as
     * a readable statement rather than an untranslated token — that, and only that, is what the
     * proof row certifies here.
     *
     * It deliberately does NOT claim to have checked that the notice says WHAT changed. Nothing
     * in this class can: the intro line is a fixed sentence per (type, locale), so it names the
     * document but never the change. Stating the substance of a change per version is a separate
     * piece of work, and until it lands this must not certify it.
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
        return $this->translate($key, $replace);
    }
}
