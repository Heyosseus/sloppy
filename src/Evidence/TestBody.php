<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Evidence;

/**
 * One test, reduced to what `SL503` compares across revisions.
 */
final readonly class TestBody
{
    /**
     * @param  string  $name  `Class::method` for PHPUnit, the description (with any `describe` prefix) for Pest.
     * @param  int  $assertions  Including those made through helpers in the same file.
     * @param  int  $trivial  Assertions that cannot fail, such as `assertTrue(true)`.
     * @param  string  $hash  The body as printed, so a renamed test can be recognised.
     */
    public function __construct(
        public string $name,
        public int $line,
        public int $assertions,
        public bool $skipped,
        public int $trivial,
        public string $hash,
    ) {}
}
