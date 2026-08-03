<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Exceptions\DocumentChangedException;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Exceptions\NotConsentBearingException;
use Pushery\LegalConsent\Exceptions\NotObjectableException;
use Pushery\LegalConsent\Exceptions\NotTerminableException;
use Pushery\LegalConsent\Exceptions\NotWithdrawableException;
use Pushery\LegalConsent\Support\ConsentContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Way C (headless): opt-in JSON API. Registered only when config `routes.api` is on.
 * The subject is the authenticated user; the consent context is built server-side.
 */
final readonly class ConsentController
{
    public function __construct(private ConsentManager $consent) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'document_key' => ['required', 'string'],
            'expected_content_hash' => ['nullable', 'string'],
        ]);

        try {
            $record = $this->consent->accept(
                $this->subject($request),
                $request->string('document_key')->toString(),
                ConsentContext::fromRequest($request, ConsentMethod::Api),
                null,
                // The hash the client last showed the subject; a mismatch means a version was
                // released since, and the client must re-show it (409) before recording.
                $request->filled('expected_content_hash') ? $request->string('expected_content_hash')->toString() : null,
            );
        } catch (DocumentChangedException $e) {
            return response()->json([
                'error' => 'document_changed',
                'message' => $e->getMessage(),
                'document_key' => $e->documentKey,
            ], JsonResponse::HTTP_CONFLICT);
        } catch (NotConsentBearingException $e) {
            // The sibling of not_withdrawable / not_objectable / not_terminable below, and the one
            // that was missing: `impressum` ships in the default registry as an informational
            // document, so a client can name a published key that accepts nothing without ever
            // having typed it. That is a client error, not an outage of this package.
            return response()->json(['error' => 'not_consent_bearing', 'message' => $e->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (LegalDocumentNotFound $e) {
            return $this->unknownDocument($request, $e);
        }

        return response()->json([
            'id' => $record->id,
            'document_key' => $record->document_key,
            'action' => $record->action->value,
        ], JsonResponse::HTTP_CREATED);
    }

    public function withdraw(Request $request): JsonResponse
    {
        $request->validate(['document_key' => ['required', 'string']]);

        try {
            $this->consent->withdraw(
                $this->subject($request),
                $request->string('document_key')->toString(),
                ConsentContext::fromRequest($request, ConsentMethod::Api),
            );
        } catch (NotWithdrawableException $e) {
            return response()->json(['error' => 'not_withdrawable', 'message' => $e->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (LegalDocumentNotFound $e) {
            return $this->unknownDocument($request, $e);
        }

        return response()->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    public function object(Request $request): JsonResponse
    {
        $request->validate(['document_key' => ['required', 'string']]);

        // Mirrors withdraw() above, and it is not symmetry for its own sake: the manager refuses
        // an objection against a consent or an informational page, and without this the refusal
        // reaches the client as a 500 — an "our fault, retry" for a request that is simply not a
        // thing. The JSON API and the Livewire component must answer the same way.
        try {
            $record = $this->consent->object(
                $this->subject($request),
                $request->string('document_key')->toString(),
                ConsentContext::fromRequest($request, ConsentMethod::Api),
            );
        } catch (NotObjectableException $e) {
            return response()->json(['error' => 'not_objectable', 'message' => $e->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (LegalDocumentNotFound $e) {
            return $this->unknownDocument($request, $e);
        }

        return response()->json([
            'id' => $record->id,
            'document_key' => $record->document_key,
            'action' => $record->action->value,
        ], JsonResponse::HTTP_CREATED);
    }

    public function terminate(Request $request): JsonResponse
    {
        $request->validate(['document_key' => ['required', 'string']]);

        try {
            $record = $this->consent->terminate(
                $this->subject($request),
                $request->string('document_key')->toString(),
                ConsentContext::fromRequest($request, ConsentMethod::Api),
            );
        } catch (NotTerminableException $e) {
            return response()->json(['error' => 'not_terminable', 'message' => $e->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (LegalDocumentNotFound $e) {
            return $this->unknownDocument($request, $e);
        }

        return response()->json([
            'id' => $record->id,
            'document_key' => $record->document_key,
            'action' => $record->action->value,
        ], JsonResponse::HTTP_CREATED);
    }

    public function status(Request $request): JsonResponse
    {
        // Cast to object so the empty case serializes as `{}` rather than `[]`. `statusFor()`
        // returns a map keyed by document key, but PHP cannot tell an empty map from an empty list,
        // so json_encode picks the array form — and an application that has published nothing yet,
        // or a subject with no consent-bearing documents, is the ordinary case rather than an edge
        // one. A client that decodes this into a dictionary fails on the array, and it fails on
        // exactly the request that carries no other signal that anything is wrong.
        return response()->json((object) $this->consent->statusFor($this->subject($request)));
    }

    /**
     * The one answer to "there is no such document being served here".
     *
     * 404 rather than 422: `document_key` is well-formed and cleared validation, the resource just
     * does not exist — 422 stays the answer for a request that names something real and is legally
     * impossible. And rather than the 500 this used to be, because a 500 tells a client "our fault,
     * retry" on an endpoint that appends to an append-only ledger, and files a consumer's typo as
     * an outage of this package in their monitoring.
     *
     * The message is written here rather than echoed from the exception on purpose: that one is a
     * STORAGE sentence naming the table and the locale it looked in, which means nothing to an API
     * client and describes internals to one. Its siblings echo theirs because theirs are domain
     * sentences the client can act on.
     *
     * RETHROWS anything that is not the active-version lookup. The catch has to sit around the
     * whole manager call, and that call ends by dispatching a domain event synchronously, after the
     * transaction commits — so a consuming application's listener that renders a document can raise
     * this very exception type once the ledger row already exists. Answering THAT with a 404 would
     * tell the client a published key does not exist, invite a retry that appends a second row, and
     * bury the listener's real failure. The two cases are told apart at the source rather than
     * guessed at here.
     */
    private function unknownDocument(Request $request, LegalDocumentNotFound $exception): JsonResponse
    {
        if (! $exception->isMissingPublishedVersion()) {
            throw $exception;
        }

        $key = $request->string('document_key')->toString();

        return response()->json([
            'error' => 'unknown_document',
            'message' => "No legal document is published under the key '{$key}'.",
            'document_key' => $key,
        ], JsonResponse::HTTP_NOT_FOUND);
    }

    private function subject(Request $request): Model
    {
        $user = $request->user();

        if (! $user instanceof Model) {
            throw new HttpException(JsonResponse::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        }

        return $user;
    }
}
