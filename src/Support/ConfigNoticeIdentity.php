<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Pushery\LegalConsent\Contracts\ResolvesNoticeIdentity;
use Pushery\LegalConsent\Models\LegalDocument;

/**
 * The shipped resolver: one declarant for the whole installation, read from
 * `legal-consent.notice_mail.identity`.
 *
 * Ignores the document, which is right for the single-operator case and wrong for a multi-tenant
 * one — that is the whole reason the seam is a contract. Everything defaults to null, so a host
 * that configures nothing is unchanged.
 */
final readonly class ConfigNoticeIdentity implements ResolvesNoticeIdentity
{
    public function forDocument(LegalDocument $document): NoticeIdentity
    {
        $identity = config('legal-consent.notice_mail.identity');
        $identity = is_array($identity) ? $identity : [];

        return new NoticeIdentity(
            declarant: $this->string($identity, 'declarant'),
            postalAddress: $this->string($identity, 'postal_address'),
            imprintUrl: $this->string($identity, 'imprint_url'),
            privacyUrl: $this->string($identity, 'privacy_url'),
            replyTo: $this->string($identity, 'reply_to'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $identity
     */
    private function string(array $identity, string $key): ?string
    {
        $value = $identity[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
