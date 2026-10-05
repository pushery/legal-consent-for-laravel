<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Support;

use Illuminate\Http\Request;
use Pushery\LegalConsent\Enums\ConsentMethod;
use Pushery\LegalConsent\Exceptions\InvalidConsentContext;

/**
 * The context captured with a ledger entry (EDPB 05/2020 Rz. 108).
 *
 * Only part of it comes from the server. The time is the server's, and the IP address is the
 * connection's, read through the application's trusted-proxy configuration. The user agent is
 * what the client sent, recorded as context rather than as proof. The request id is taken only
 * where the server side vouches for it: assigned by the application after the request arrived,
 * or carried in through a trusted proxy. Straight from a client, the header could name any
 * request, and a record would point a reader of the logs at the wrong one.
 *
 * Each value is checked against the `legal_consents` column it is written to when the context is
 * built, because the engines answer a value that does not fit differently: SQLite keeps it,
 * PostgreSQL and MySQL refuse the write.
 */
final readonly class ConsentContext
{
    /** The width of `legal_consents.source`. */
    public const int SOURCE_MAX_LENGTH = 32;

    /** The width of `legal_consents.request_id`. */
    public const int REQUEST_ID_MAX_LENGTH = 64;

    /** What `legal_consents.user_agent`, a `text` column, holds on MySQL, counted in bytes. */
    public const int USER_AGENT_MAX_BYTES = 65535;

    /**
     * @throws InvalidConsentContext when a value does not fit the column it is written to
     */
    public function __construct(
        public ConsentMethod $method,
        public ?string $source = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
        public ?string $locale = null,
    ) {
        if ($source !== null && mb_strlen($source) > self::SOURCE_MAX_LENGTH) {
            throw InvalidConsentContext::tooLong('source', mb_strlen($source), self::SOURCE_MAX_LENGTH);
        }

        if ($requestId !== null && mb_strlen($requestId) > self::REQUEST_ID_MAX_LENGTH) {
            throw InvalidConsentContext::tooLong('requestId', mb_strlen($requestId), self::REQUEST_ID_MAX_LENGTH);
        }

        if ($userAgent !== null && strlen($userAgent) > self::USER_AGENT_MAX_BYTES) {
            throw InvalidConsentContext::userAgentTooLarge(strlen($userAgent));
        }

        if ($ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            throw InvalidConsentContext::notAnIpAddress($ipAddress);
        }
    }

    /**
     * Build the context from the current request (the strongest proof context).
     */
    public static function fromRequest(Request $request, ConsentMethod $method, ?string $source = null, ?string $locale = null): self
    {
        $userAgent = $request->userAgent();
        $requestId = self::vouchedRequestId($request);
        $ipAddress = $request->ip();

        return new self(
            method: $method,
            source: $source,
            // The server supplies the address, so one it cannot read, such as the socket path some
            // setups put in REMOTE_ADDR, is recorded as none: no caller could correct it.
            ipAddress: is_string($ipAddress) && filter_var($ipAddress, FILTER_VALIDATE_IP) !== false ? $ipAddress : null,
            userAgent: is_string($userAgent) ? mb_substr($userAgent, 0, 1000) : null,
            requestId: $requestId,
            locale: $locale,
        );
    }

    /**
     * The `X-Request-Id` the server side vouches for, cut to the column's 64 characters.
     *
     * What arrived with the request stays in the server bag. A header that differs from it was
     * set by the application; one that does not came from the client, and counts only through a
     * trusted proxy, which is where an edge that assigns request ids sits.
     */
    private static function vouchedRequestId(Request $request): ?string
    {
        $requestId = $request->headers->get('X-Request-Id');

        if ($requestId === null || $requestId === '') {
            return null;
        }

        if ($requestId === $request->server->get('HTTP_X_REQUEST_ID') && ! $request->isFromTrustedProxy()) {
            return null;
        }

        return mb_substr($requestId, 0, self::REQUEST_ID_MAX_LENGTH);
    }

    /**
     * Build a context with no request (CLI, queue, import).
     */
    public static function forMethod(ConsentMethod $method, ?string $source = null, ?string $locale = null): self
    {
        return new self(method: $method, source: $source, locale: $locale);
    }
}
