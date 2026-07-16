<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Contracts;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * A legal-change notification that renders a mail message — implemented by every notice-mode
 * notification (ReconsentRequired, LegalChangeInformational, DeemedConsentNotice). Lets the
 * dispatch command render the notice body for the durable-medium proof without depending on
 * a concrete notification class.
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
