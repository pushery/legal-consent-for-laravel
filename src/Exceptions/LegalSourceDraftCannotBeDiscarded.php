<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Exceptions;

use RuntimeException;

/**
 * Someone asked to discard the SOURCE draft of a text, and there is no coherent answer to that.
 *
 * Every translation measures its freshness against the source's hash, and the source is what a
 * translation falls back to when it has none of its own. Removing it would leave every other locale
 * of that key pointing at a text that no longer exists — stale against nothing, with no way back
 * short of writing the source again from memory.
 *
 * So the refusal is structural rather than a policy a caller may pass a flag to skip. Discarding is
 * for a locale nobody wants: a machine translation that came out wrong holds a language in a state
 * that is neither published nor gone. The source is never in that state, because it is the state
 * everything else is measured against.
 */
final class LegalSourceDraftCannotBeDiscarded extends RuntimeException
{
    public static function for(string $key, string $locale): self
    {
        return new self(
            "The source draft for '{$key}' ({$locale}) cannot be discarded: every translation of "
            .'that key measures its freshness against it, and falls back to it when it has no hash '
            .'of its own. Discard a translation, or rewrite the source with save().'
        );
    }
}
