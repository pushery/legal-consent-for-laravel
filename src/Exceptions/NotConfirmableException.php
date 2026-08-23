<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * A double-opt-in confirmation was offered for something that cannot be confirmed.
 *
 * Three shapes, kept distinct because they mean different things both to the person who clicked
 * and to whoever later has to prove what happened:
 *
 *  - there is no pending request — nobody entered this subject for this document, or the request
 *    has already been confirmed, withdrawn or superseded;
 *  - the confirmation window closed;
 *  - a new MAJOR version was published after the request, so confirming would freeze a text the
 *    subject never saw.
 *
 * Answering all three with "invalid link" is the easy path and the wrong one: someone who
 * confirmed twice, someone whose window expired, and someone looking at a document that has since
 * changed each need a different next step.
 *
 * A document that is not a voluntary consent at all is a fourth case and NOT this exception —
 * {@see NotGrantableException} already says it: a contract is agreed where its full text is
 * presented, never confirmed by following a link in an e-mail.
 */
final class NotConfirmableException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function noPendingRequest(string $documentKey): self
    {
        return new self(
            "There is no pending opt-in request for '{$documentKey}': nothing was entered for this "
            .'subject, or the request has already been confirmed, withdrawn or superseded. A '
            .'confirmation must follow a request — writing one on its own would assert a two-step '
            .'consent that only ever had one step.',
            'no_pending_request',
        );
    }

    public static function windowClosed(string $documentKey, string $window): self
    {
        return new self(
            "The opt-in request for '{$documentKey}' is older than the configured confirmation "
            ."window ({$window}). A confirmation is a statement about what the subject wanted at "
            .'the moment they were asked, and after long enough it is no longer that. Ask again '
            .'rather than accepting it late.',
            'window_closed',
        );
    }

    public static function superseded(string $documentKey, int $requested, int $current): self
    {
        return new self(
            "The opt-in request for '{$documentKey}' was made against major version {$requested} and "
            ."the active version is now {$current}. Confirming would freeze a text the subject never "
            .'read (Art. 7(1)); a new major is a material change, which is the same line the '
            .'re-consent gate draws. Show the current text and ask again.',
            'superseded',
        );
    }
}
