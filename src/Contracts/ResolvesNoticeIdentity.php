<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Contracts;

use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\ConfigNoticeIdentity;
use Pushery\LegalConsent\Support\NoticeIdentity;

/**
 * Resolves WHO is declaring a change, per document.
 *
 * A contract rather than a config block, because in a multi-tenant application a single global
 * declarant names the wrong legal person in every tenant but one — which is worse than naming
 * nobody, since a notice that identifies the wrong declarant is affirmatively misleading where an
 * unnamed one is merely incomplete.
 *
 * The shipped {@see ConfigNoticeIdentity} reads the static block and
 * ignores the document. Bind your own to vary it per tenant.
 */
interface ResolvesNoticeIdentity
{
    public function forDocument(LegalDocument $document): NoticeIdentity;
}
