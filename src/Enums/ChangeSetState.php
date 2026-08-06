<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Enums;

/**
 * Whether a change description is still being written or has been frozen by a publish.
 *
 * Two states and no more, because the freeze is the only transition that matters: before it the
 * row is ordinary working data, after it the row is the record of what a subject was told, and no
 * third state sits usefully between those.
 */
enum ChangeSetState: string
{
    case Draft = 'draft';

    case Published = 'published';

    /**
     * Whether rows in this state refuse every write. The database triggers ask the same question of
     * the stored value, so this is the one place the answer is written down for both.
     */
    public function isFrozen(): bool
    {
        return $this === self::Published;
    }
}
