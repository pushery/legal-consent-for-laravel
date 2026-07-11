<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

/**
 * The source format a driver hands to the render pipeline. Markdown is rendered to
 * HTML; HTML is passed through. Both are sanitized before hashing.
 */
enum ContentFormat: string
{
    case Markdown = 'markdown';
    case Html = 'html';
}
