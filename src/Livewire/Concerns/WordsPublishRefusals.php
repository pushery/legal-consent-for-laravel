<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire\Concerns;

use Pushery\LegalConsent\Contracts\NamesLegalTexts;
use Pushery\LegalConsent\Enums\PublishRefusal;
use Pushery\LegalConsent\Exceptions\LegalPublishRefused;

/**
 * A publisher's refusal in the words of the admin screen that met it.
 *
 * The editor and the overview both catch {@see LegalPublishRefused} and show why nothing was
 * released, inside a status line that is already in the reader's language and already names the
 * document. They used to hand over the refusal's message, an English sentence for a log that names
 * the document by its configuration key and a language by its code. A refusal that carries its
 * reason is worded from that reason and its values instead, and every language in it is named the
 * way the rest of the screen names languages, through {@see NamesLegalTexts}.
 *
 * One without a reason is one a screen cannot reach through its own controls, and it is shown as its
 * message ({@see PublishRefusal} lists which refusals those are).
 */
trait WordsPublishRefusals
{
    private function publishRefusalReason(LegalPublishRefused $refusal): string
    {
        if (! $refusal->reason instanceof PublishRefusal) {
            return $refusal->getMessage();
        }

        $names = app(NamesLegalTexts::class);
        $values = $refusal->values;

        foreach (['language', 'other_language'] as $placeholder) {
            if (array_key_exists($placeholder, $values)) {
                $values[$placeholder] = $names->language((string) $values[$placeholder]);
            }
        }

        return (string) __($refusal->reason->label(), $values);
    }
}
