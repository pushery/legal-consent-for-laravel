<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

/**
 * Who produced a draft's current text.
 *
 * Machine-origin text is not a lesser draft — it is unpublishable until a human reviews it, on
 * every path including the CLI, because the gate reads {@see ReviewState} and only an explicit
 * human act sets Reviewed. This enum records HOW the text came to be, so an audit can answer
 * "was this contract drafted by a machine, and who signed it off?" — the question that matters
 * when a `legal_basis: contract` document is later used to prove what a subject agreed to.
 */
enum DraftOrigin: string
{
    /** A human wrote or edited this text. */
    case Authored = 'authored';

    /** A machine translation produced this text. Always lands as ReviewState::Draft. */
    case Machine = 'machine';
}
