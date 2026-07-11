<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Http\Request;
use Pushery\LegalConsent\Enums\ConsentMethod;

/**
 * The server-side context captured with a ledger entry. Built from trusted, server-side
 * signals only — never from client-supplied IP/time/user-agent claims — so the proof is
 * meaningful (EDPB 05/2020 Rz. 108).
 */
final readonly class ConsentContext
{
    public function __construct(
        public ConsentMethod $method,
        public ?string $source = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
        public ?string $locale = null,
    ) {}

    /**
     * Build the context from the current request (the strongest proof context).
     */
    public static function fromRequest(Request $request, ConsentMethod $method, ?string $source = null, ?string $locale = null): self
    {
        $userAgent = $request->userAgent();
        $requestId = $request->headers->get('X-Request-Id');

        return new self(
            method: $method,
            source: $source,
            ipAddress: $request->ip(),
            userAgent: is_string($userAgent) ? mb_substr($userAgent, 0, 1000) : null,
            requestId: $requestId !== null && $requestId !== '' ? mb_substr($requestId, 0, 64) : null,
            locale: $locale,
        );
    }

    /**
     * Build a context with no request (CLI, queue, import).
     */
    public static function forMethod(ConsentMethod $method, ?string $source = null, ?string $locale = null): self
    {
        return new self(method: $method, source: $source, locale: $locale);
    }
}
