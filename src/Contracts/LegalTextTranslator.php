<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Contracts;

use Pushery\LegalConsent\Support\LegalDraftWriter;

/**
 * The seam a consumer binds to translate a legal text — and the package's ENTIRE AI surface.
 *
 * Deliberately provider-agnostic: no SDK types, no model names, no keys. Every app already has its
 * own LLM wiring (its own client, prompt cache, spend controls, test fake), and a package that
 * shipped a second one would fight it. So the package owns the part that must not vary — a machine
 * draft can never be published until a human reviews it, on every path — and leaves the part that
 * legitimately varies to the app.
 *
 * The invariant lives in {@see LegalDraftWriter::applyTranslation()},
 * not here: whatever an implementation returns is sanitized through the one pipeline and stored as
 * `origin = Machine, review_state = Draft`. There is no code path from a translation to Reviewed,
 * so an implementation cannot opt out of the review gate however it is written.
 */
interface LegalTextTranslator
{
    /**
     * Translate a sanitized HTML fragment, preserving its structure exactly.
     *
     * An implementation must translate only the text between tags — never add, remove, merge,
     * split, reorder or summarize a clause, and never adapt the legal substance to the target
     * jurisdiction. This is a translation, not legal drafting: a model that "improves" a contract
     * clause produces a different contract.
     *
     * @param  string  $html  the source text, already sanitized
     * @param  string  $sourceLocale  the locale $html is written in
     * @param  string  $targetLocale  the locale to translate into
     * @return string an HTML fragment; the caller re-sanitizes before storage
     */
    public function translate(string $html, string $sourceLocale, string $targetLocale): string;
}
