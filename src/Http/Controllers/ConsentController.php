<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Exceptions\DocumentChangedException;
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
        }

        return response()->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    public function object(Request $request): JsonResponse
    {
        $request->validate(['document_key' => ['required', 'string']]);

        $record = $this->consent->object(
            $this->subject($request),
            $request->string('document_key')->toString(),
            ConsentContext::fromRequest($request, ConsentMethod::Api),
        );

        return response()->json([
            'id' => $record->id,
            'document_key' => $record->document_key,
            'action' => $record->action->value,
        ], JsonResponse::HTTP_CREATED);
    }

    public function terminate(Request $request): JsonResponse
    {
        $request->validate(['document_key' => ['required', 'string']]);

        $record = $this->consent->terminate(
            $this->subject($request),
            $request->string('document_key')->toString(),
            ConsentContext::fromRequest($request, ConsentMethod::Api),
        );

        return response()->json([
            'id' => $record->id,
            'document_key' => $record->document_key,
            'action' => $record->action->value,
        ], JsonResponse::HTTP_CREATED);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->consent->statusFor($this->subject($request)));
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
