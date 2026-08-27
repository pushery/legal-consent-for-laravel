<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
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
            //
            // The TRANSLATED sentence, never $e->getMessage(). The exception carries a hardcoded
            // English string naming the document key and the internal enum value, and the settings
            // stub renders whatever lands under this key verbatim inside `<p role="alert">` — so
            // the developer sentence would be announced by a screen reader, in English, on a
            // surface whose every other line is translated because each is a statement about the
            // subject's legal position. The developer sentence still has a reader: an operator
            // finding out that a stale page or a custom stub is posting a key that can never
            // succeed. That reader is the log.
            Log::warning('legal-consent: refused a withdrawal on a document that cannot be withdrawn', [
                'document_key' => $key,
                'reason' => $e->getMessage(),
            ]);

            return $this->backToOwnOrigin($request)
                ->with(self::ERROR_KEY, (string) __('legal-consent::ui.not_withdrawable'));
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

        return $this->backToOwnOrigin($request)
            ->with(self::STATUS_KEY, (string) __('legal-consent::ui.withdrawn_confirmation'));
    }

    /**
     * Back to the page the form was posted from — but only while that page is this application's.
     *
     * A bare `back()` resolves `url()->previous()`, which reads the `Referer` header FIRST and
     * only falls back to the session when there is none. The package already refuses to trust
     * that value in its other post-legal-flow redirect (ReConsentForm::submit): returning a
     * subject to a foreign origin in the same response that confirms a legal act is an ideal
     * phishing hand-off, and the confirmation the foreign page will not show is flashed all the
     * same. This route sat in the same trust context without the check.
     *
     * Not presented as an exploit: the route is behind `['web', 'auth']` by default, `web` carries
     * CSRF protection, and a legitimate same-origin submission has a same-origin Referer that no
     * attacker sets. It is the package's own standard, applied at both of the places that need it.
     */
    private function backToOwnOrigin(Request $request): RedirectResponse
    {
        $home = config('legal-consent.routes.home', '/');
        $fallback = is_string($home) && $home !== '' ? $home : '/';

        $previous = url()->previous($fallback);

        return redirect()->to($this->isSameOrigin($previous, $request) ? $previous : $fallback);
    }

    /**
     * A redirect target is safe only while it stays on this application's origin: a rooted
     * relative path, or an absolute URL whose host is this request's own.
     */
    private function isSameOrigin(string $target, Request $request): bool
    {
        // Rejected, not sanitized. A browser drops the C0 controls and whitespace in a URL
        // wherever they sit rather than only at the ends, so `/<TAB>evil.example` is fetched as
        // `//evil.example` — a check that trimmed the ends would approve a string that becomes a
        // foreign authority afterwards. No legitimate Referer carries a raw control or space;
        // browsers percent-encode them.
        if ($target === '' || preg_match('/[\x00-\x20\x7F]/', $target) === 1) {
            return false;
        }

        // The shapes parse_url does NOT read as an authority but a browser does: a backslash
        // (browsers treat `\` as `/`, so `/\evil` becomes `//evil`) and a protocol-relative
        // `//host`, which has no scheme for parse_url to hang a host on.
        if (str_contains($target, '\\') || str_starts_with($target, '//')) {
            return false;
        }

        $host = parse_url($target, PHP_URL_HOST);

        // No host → accept only a rooted relative path (`/…`); a scheme like `mailto:`/`tel:`, a
        // bare word or a fragment is dropped. Otherwise the host must be this request's own.
        return $host === null ? str_starts_with($target, '/') : $host === $request->getHost();
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
