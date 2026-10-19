<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Content;

use Pushery\LegalConsent\Exceptions\UnresolvedPlaceholders;

/**
 * The placeholders a legal text may use, each with the value it takes, and the one place that
 * fills them in.
 *
 * A placeholder is a name in double braces, `{{ shop.domain }}`, with or without spaces inside the
 * braces. Filling refuses a text that uses a name it was not given, and a name whose value is
 * null or blank, and the refusal names every such placeholder at once, so one correction fixes the
 * text rather than one per attempt.
 *
 * A value is set on one line and escaped for the format of the text it goes into: every ASCII
 * punctuation character takes a backslash in Markdown, and HTML takes entities. So a value reads as
 * it was written and never becomes markup, whatever a shop calls itself.
 */
final readonly class Placeholders
{
    /** A name of letters, digits, `_`, `-` and `.`, starting with a letter, in double braces. */
    public const string PATTERN = '/\{\{\s*([A-Za-z][A-Za-z0-9_.-]*)\s*\}\}/';

    /**
     * @param  array<string, string|null>  $values  every name a text may use, with its value
     */
    public function __construct(
        public array $values = [],
    ) {}

    /**
     * The text with every placeholder replaced by its escaped value.
     *
     * @throws UnresolvedPlaceholders when the text uses a name that has no value here
     */
    public function fill(string $text, ContentFormat $format): string
    {
        preg_match_all(self::PATTERN, $text, $matches);

        $unknown = [];
        $missing = [];

        foreach (array_values(array_unique($matches[1])) as $name) {
            if (! array_key_exists($name, $this->values)) {
                $unknown[] = $name;
            } elseif (trim((string) $this->values[$name]) === '') {
                $missing[] = $name;
            }
        }

        if ($unknown !== [] || $missing !== []) {
            throw UnresolvedPlaceholders::for($unknown, $missing);
        }

        return (string) preg_replace_callback(
            self::PATTERN,
            fn (array $match): string => $this->escape((string) ($this->values[$match[1]] ?? ''), $format),
            $text,
        );
    }

    private function escape(string $value, ContentFormat $format): string
    {
        // One line: a line break inside a value could end the paragraph, the list item or the
        // table cell it stands in.
        $line = (string) preg_replace('/\s+/u', ' ', trim($value));

        return $format === ContentFormat::Html
            ? htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
            : (string) preg_replace('/[!-\/:-@\[-`{-~]/', '\\\\$0', $line);
    }
}
