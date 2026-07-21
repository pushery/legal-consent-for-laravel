<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire\Concerns;

/**
 * A status message plus a monotonic nonce, so an aria-live region re-announces even when the SAME
 * message is set twice.
 *
 * Livewire's morph writes nothing to the DOM for an unchanged string, so a repeated identical status
 * (a re-consent race firing twice, a blocked release pressed twice) would announce nothing and
 * Alpine's `x-effect` — which tracks only the value — would not re-run: the action would read as a
 * no-op. Bumping `$statusNonce` on every set gives the view a value that always changes, which it
 * keys the announcement and the focus move off.
 *
 * `$statusNonce` is intentionally UNLOCKED: it is a render nonce, not proof. A client that bumps it
 * only re-announces the text the server already set — there is nothing to forge.
 */
trait AnnouncesStatus
{
    /** The message rendered into the component's aria-live region. */
    public string $status = '';

    public int $statusNonce = 0;

    protected function setStatus(string $status): void
    {
        $this->status = $status;
        $this->statusNonce++;
    }
}
