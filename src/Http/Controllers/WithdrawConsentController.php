<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Exceptions\NotWithdrawableException;
use Pushery\LegalConsent\Support\ConsentContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The session-backed withdrawal a plain HTML form can post to.
 *
 * Registered only when `legal-consent.routes.web` is on, behind `['web', 'auth']` by default —
 * so it has a session and CSRF protection, which is exactly what the JSON API (Way C, `api`
 * middleware) does not. It answers with a redirect back to the page the form was on, because a
 * form post that lands on an empty 204 leaves the subject staring at a blank document.
 *
 * It exists for ONE control: the withdraw button in the framework-agnostic settings stub, which
 * had no route to point at and therefore posted to `#`. The stub cannot call a Livewire action —
 * being framework-agnostic is the point of it — so without this the package promised Art. 7(3)
 * in a comment and shipped a button that did nothing.
 *
 * The status is flashed under a namespaced key rather than the conventional `status`, which
 * applications use for their own messages: a withdrawal confirmation appearing under a key the
 * host also writes would be indistinguishable from the host's own, and this one is a statement
 * about a legal position.
 */
final readonly class WithdrawConsentController
{
    public const string STATUS_KEY = 'legal-consent.status';

    public const string ERROR_KEY = 'legal-consent.error';

    public function __construct(private ConsentManager $consent) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate(['document_key' => ['required', 'string']]);

        $key = $request->string('document_key')->toString();

        try {
            $this->consent->withdraw(
                $this->subject($request),
                $key,
                ConsentContext::fromRequest($request, ConsentMethod::SettingsToggle),
            );
        } catch (NotWithdrawableException $e) {
            // A contract cannot be "withdrawn" — it is ended by ending the relationship — and the
            // manager refuses that rather than writing a row asserting a state that does not
            // legally exist. Answered as a message on the page, not a 500: the subject did
            // nothing wrong, and a form post has nowhere else to put an explanation.
            return back()->with(self::ERROR_KEY, $e->getMessage());
        } catch (LegalDocumentNotFound $e) {
            // Only the active-version lookup means "no such document being served here". Anything
            // else — a consuming application's listener raising this same type AFTER the ledger
            // row was written — is rethrown, exactly as the JSON API does: answering that with a
            // 404 would invite a retry that appends a second row and bury the real failure.
            if (! $e->isMissingPublishedVersion()) {
                throw $e;
            }

            throw new HttpException(Response::HTTP_NOT_FOUND, "No legal document is published under the key '{$key}'.", $e);
        }

        return back()->with(self::STATUS_KEY, (string) __('legal-consent::ui.withdrawn_confirmation'));
    }

    private function subject(Request $request): Model
    {
        $user = $request->user();

        if (! $user instanceof Model) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        }

        return $user;
    }
}
