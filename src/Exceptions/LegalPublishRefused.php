<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use Pushery\LegalConsent\Enums\PublishRefusal;
use RuntimeException;

/**
 * The publisher refused a version, and the message says why and what to publish instead.
 *
 * Every refusal the publisher raises over an operator's request is one of these: a notice mode the
 * document type does not allow, a major that has to gate, an objection window that is missing or
 * ends after the effective date, a version that already exists or would go backwards, a locale or a
 * regime the configuration does not know. They are the operator's to fix, so a screen shows the
 * message instead of failing the request.
 *
 * The message is an English sentence for a log or a command line. A refusal an admin screen can
 * reach also carries its reason and the values it was built from, so the screen words it in its own
 * language and names the languages the way the rest of the screen does. One without a reason is shown
 * as its message ({@see PublishRefusal} says which ones those are, and why).
 *
 * It extends RuntimeException because that is what these refusals were before they had a type, so a
 * caller catching that still catches them. A caller that wants only refusals catches this one, and a
 * database failure, which is a RuntimeException as well, travels on as the error it is.
 */
final class LegalPublishRefused extends RuntimeException
{
    /**
     * @param  array<string, int|string>  $values  the placeholders of the reason's sentence; a language
     *                                             is given as its code, under `language` or `other_language`
     */
    public function __construct(
        string $message = '',
        public readonly ?PublishRefusal $reason = null,
        public readonly array $values = [],
    ) {
        parent::__construct($message);
    }

    /**
     * A refusal a screen can word, with the English message for a log beside it.
     *
     * @param  array<string, int|string>  $values
     */
    public static function because(PublishRefusal $reason, string $message, array $values): self
    {
        return new self($message, $reason, $values);
    }
}
