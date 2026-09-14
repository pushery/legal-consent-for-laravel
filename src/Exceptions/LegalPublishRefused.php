<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * The publisher refused a version, and the message says why and what to publish instead.
 *
 * Every refusal the publisher raises over an operator's request is one of these: a notice mode the
 * document type does not allow, a major that has to gate, an objection window that is missing or
 * ends after the effective date, a version that already exists or would go backwards, a locale or a
 * regime the configuration does not know. They are the operator's to fix, so a screen shows the
 * message instead of failing the request.
 *
 * It extends RuntimeException because that is what these refusals were before they had a type, so a
 * caller catching that still catches them. A caller that wants only refusals catches this one, and a
 * database failure, which is a RuntimeException as well, travels on as the error it is.
 */
final class LegalPublishRefused extends RuntimeException {}
