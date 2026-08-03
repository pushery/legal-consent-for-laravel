<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Events;

use Pushery\LegalConsent\Models\LegalDraft;

/**
 * A human signed off on a draft's exact bytes — the one act that makes a text publishable, and the
 * only thing standing between a machine translation and a binding contract.
 *
 * Consumers should log this with the actor: when a `legal_basis: contract` document is later used
 * to prove what a subject agreed to, "who confirmed that this German text says what the English
 * says" is the question that matters. The package deliberately does not store the actor itself
 * (personal data with no erasure home here) — it hands it to the consumer's own audit log.
 */
final readonly class LegalDraftReviewed
{
    public function __construct(
        public LegalDraft $draft,
        public ?string $actor = null,
    ) {}
}
