<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

/**
 * A source whose absent text means NOBODY HAS WRITTEN IT YET — not that something is broken.
 *
 * The bulk publish needs to tell those two apart and could not: both the markdown driver and the
 * draft source raise the same `LegalDocumentNotFound`, so `--only-missing` had to answer both the
 * same way, and it answered "warn". For a draft that is right — a deploy line cannot make an
 * editor write a legal text, and failing there paints every deploy red for something nobody at
 * the console can act on. For a file that should be in the repository it is exactly wrong: that
 * is a deployment missing a file, and warning about it produces the empty legal page behind a
 * green deploy that the command exists to prevent.
 *
 * ⚠️ IT IS A MARKER ON THE SOURCE, NOT A CHECK ON THE CONFIGURED NAME, and that is the point of
 * having it. Reading `source === 'drafts'` would work for the two drivers shipped here and be
 * wrong for the case the package is built around: a consumer's own editorial source — a CMS, a
 * review queue — is authored by people too, and would be told its normal empty state is a
 * deployment fault. An interface lets that source say so; a string comparison never could.
 *
 * The default is deliberately the strict one. A source that does not declare this is treated as
 * provisioned, so an unmarked custom driver fails loudly rather than skipping quietly — the
 * recoverable direction, because a loud failure is read and a quiet skip is not.
 */
interface AwaitsAuthoring {}
