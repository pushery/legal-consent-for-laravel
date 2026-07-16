<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Pushery\LegalConsent\Models\LegalConsent;

/**
 * A subject objected (Widerspruch) — recorded as a new append-only entry. Either a
 * rebuttal of a deemed-consent fiction (§ 308 Nr. 5 lit. a BGB), or an Art. 21 objection
 * to legitimate-interest processing. In the Art. 21 case the consuming app MUST stop that
 * processing for the subject where no overriding grounds apply — the package emits the
 * signal; it cannot know the processing.
 */
final readonly class ConsentObjected
{
    public function __construct(public LegalConsent $consent) {}
}
