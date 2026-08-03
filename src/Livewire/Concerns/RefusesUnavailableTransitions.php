<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Livewire\Concerns;

use Closure;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Exceptions\NotObjectableException;
use Pushery\LegalConsent\Exceptions\NotTerminableException;
use Pushery\LegalConsent\Exceptions\NotWithdrawableException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answers a transition this instance does not have with a 404 instead of letting it surface as a
 * 500.
 *
 * Every public method of a Livewire component is a reachable endpoint once the component is
 * embedded, and its arguments come from the browser. Two of the manager's refusals therefore reach
 * the HTTP layer as ordinary requests rather than as programming errors:
 *
 *  - the document key was never published, or was unpublished between the render and the click;
 *  - the document's class cannot carry the transition (an objection against a consent, a
 *    termination against a privacy notice).
 *
 * Both used to arrive as a 500 — "our fault, retry" — for a request that is simply not a thing.
 * The manager's guard is untouched and still does its real job, which is keeping rows that assert
 * legally impossible states out of an append-only ledger; only the answer to the client changes.
 *
 * 404 rather than 403, matching `$allowObjection` / `$allowTermination`: a transition this instance
 * does not offer is one that does not exist here, and "you may not" would confirm that it does.
 *
 * The refusal's own sentence rides along as the exception message, and it is worth being exact
 * about how far that carries, because the obvious assumption is wrong in both directions:
 *
 *  - the SUBJECT sees nothing. The rendered body is the framework's generic 404 page — measured,
 *    after an earlier version of this comment claimed the message reached it. That is the right
 *    split rather than a shortfall: the sentence names the document key and its legal class, which
 *    is the template author's business and none of the subject's;
 *  - the DEVELOPER does not see it either, by default. `NotFoundHttpException` extends
 *    `HttpException`, which sits in Laravel's internal don't-report list, so nothing is logged and
 *    the error page shows only "Not Found". It reaches an application that reports 404s itself — a
 *    custom handler, an APM — and nowhere else.
 *
 * So the message is not a diagnostic channel this package can promise; it is a value attached to
 * the exception for whoever chooses to look. Do not document it as one.
 *
 * The exception is thrown directly rather than through `abort()`, matching what `ConsentController`
 * already does with `HttpException`. `abort()` is a Foundation-only global, and the contract test
 * that ratchets those caught it as a new one — correctly, even though the file next door already
 * calls `abort_unless()`. Throwing is not a workaround for that guard: it needs no global at all,
 * and it can carry the refusal as the PREVIOUS exception, which `abort()` has no way to pass.
 */
trait RefusesUnavailableTransitions
{
    /**
     * NOT named `transition()`, and PRIVATE on purpose — both for the same reason.
     *
     * `Livewire\Component` already carries a public `transition()` (via HandlesTransitions), so the
     * obvious name is taken. Private, PHP refuses to reduce the visibility and the collision is a
     * load-time fatal — loud, and caught by the first test that boots the component. Public, it
     * would have silently OVERRIDDEN Livewire's own transition API instead, and what broke would
     * have been the JS transitions, somewhere else, with nothing pointing back here.
     *
     * The closure's return type is `mixed` because the manager's transitions return the ledger row
     * they appended and this helper has no use for it — the caller records, it does not read back.
     *
     * @param  Closure(): mixed  $transition
     */
    private function guardedTransition(Closure $transition): void
    {
        try {
            $transition();
        } catch (LegalDocumentNotFound $e) {
            // Only the active-version lookup is a client error. The same type is raised while
            // RENDERING a document, and a consuming app's listener on the recorded event can raise
            // it after the ledger row is already committed — answering that with a 404 would hide
            // their bug behind a status code that says the document does not exist.
            if (! $e->isMissingPublishedVersion()) {
                throw $e;
            }

            throw new NotFoundHttpException($e->getMessage(), $e);
        } catch (NotObjectableException|NotTerminableException|NotWithdrawableException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }
    }
}
