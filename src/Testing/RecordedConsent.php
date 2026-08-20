<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Testing;

use Illuminate\Database\Eloquent\Model;
use Pushery\LegalConsent\Enums\ConsentAction;
use Pushery\LegalConsent\Support\ConsentContext;

/**
 * One write the {@see ConsentFake} intercepted, kept whole so a test can assert on any part of it.
 *
 * The subject is held as a class-name/key pair rather than as the model, because a consuming test
 * routinely records against a model it then reloads: holding the instance would make an assertion
 * depend on object identity, which is not the thing anybody means by "this user accepted".
 */
final readonly class RecordedConsent
{
    public function __construct(
        public Model $subject,
        public string $subjectType,
        public int|string|null $subjectKey,
        public string $documentKey,
        public ConsentAction $action,
        public ConsentContext $context,
        public ?string $locale,
        public ?string $expectedContentHash = null,
    ) {}

    public function isFor(Model $subject): bool
    {
        return $subject::class === $this->subjectType && self::keyOf($subject) === $this->subjectKey;
    }

    /**
     * The subject's primary key, narrowed.
     *
     * `Model::getKey()` is `mixed` because a key can be anything a cast returns. Every caller here
     * needs one answer, so the narrowing happens ONCE, in the place both the capture and the
     * comparison go through — narrowing it twice is how a subject gets stored under one key and
     * looked up under another.
     */
    public static function keyOf(Model $subject): int|string|null
    {
        $key = $subject->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}
