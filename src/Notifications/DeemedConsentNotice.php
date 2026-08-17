<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The deemed-consent (Zustimmungsfiktion) notice: a minor/peripheral CONTRACT change where
 * silence counts as acceptance (NoticeMode::DeemedConsent). The `warning` line is the
 * § 308 Nr. 5 lit. b BGB special warning — that not objecting by the deadline is treated as
 * consent — which is a VALIDITY CONDITION of the fiction, not courtesy copy. It also states
 * the change, the objection deadline, and the free right to terminate before the effective
 * date. Deemed consent is lawful only for a contract (BGH XI ZR 26/20); a privacy notice and
 * a real consent never bind on silence, so this notice is contract-only.
 */
class DeemedConsentNotice extends ChangeNotification
{
    protected function translationGroup(): string
    {
        return 'deemed';
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

        $mail = (new MailMessage)
            ->subject($this->line('subject', $replace))
            ->line($this->line('intro', $replace));

        $this->addChangeItems($mail, $this->document);

        $mail->line($this->line('warning', $replace)) // § 308 Nr. 5 lit. b — silence = consent by :deadline
            ->action($this->line('cta', $replace), $this->ctaUrl())
            ->line($this->line('termination', $replace));

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
            'object_by' => $this->document->objection_deadline?->toIso8601String(),
            'effective_at' => $this->document->enforce_from?->toIso8601String(),
        ];
    }

    /**
     * The § 308 Nr. 5 lit. b warning is what makes the fiction valid, so the proof may only
     * certify this notice once that line actually rendered — an app that publishes an empty or
     * missing `notifications.deemed.warning` override ships a void notice, and the proof row
     * must say so rather than certify content that is not there.
     *
     * The free-termination line is held to the same standard, and only where it is owed: for a
     * payment contract § 675g Abs. 2 Satz 3 BGB puts the termination notice on exactly the same
     * footing as the silence warning, so a version that offers termination and then drops the
     * line is as deficient as one missing the warning. A version that offers none owes none, and
     * asserting the line there would fail a notice that is complete.
     */
    public function mandatoryContentPresent(): bool
    {
        $blank = ['deadline' => '', 'effective' => '', 'title' => ''];

        // An adverse entry makes the termination line owed whether or not the operator set the
        // flag: § 675g Abs. 2 Satz 3 BGB ties the notice to the change being disadvantageous, not
        // to a checkbox. Removing something, narrowing a right or widening a purpose is exactly
        // that, and a fiction that binds silence to it without naming the way out is not valid.
        $terminationOwed = $this->document->offers_termination || $this->hasAdverseChangeItem($this->document);

        if ($terminationOwed && $this->line('termination', $blank) === '') {
            return false;
        }

        return $this->line('warning', $blank) !== ''
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
