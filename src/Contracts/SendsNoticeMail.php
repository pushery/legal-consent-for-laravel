<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Contracts;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * A legal-change notification that renders a mail message and says whether it carries the
 * mandatory content of its notice mode. Every notice-mode notification implements it through
 * `ChangeNotification`, and the dispatch command asks `mandatoryContentPresent()` once per
 * version and reports a notice that lacks the content. The durable-medium proof is written by
 * the delivery listener, and only for a `ChangeNotification`: `notice_mail.notification` accepts
 * no other class for the sweep, and a notice that implements this contract alone gets no proof.
 */
interface SendsNoticeMail
{
    public function toMail(object $notifiable): MailMessage;

    /**
     * Whether this notice actually carries the mandatory content its regime demands — the
     * durable-medium proof row records this verbatim, so it must VERIFY the mode-specific
     * mandatory line (for a deemed-consent notice, the § 308 Nr. 5 lit. b "silence = consent"
     * warning is a validity condition), never merely that some text was rendered. A false
     * value makes a deficient notice detectable instead of silently certified as complete.
     */
    public function mandatoryContentPresent(): bool;
}
