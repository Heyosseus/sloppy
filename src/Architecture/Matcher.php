<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * One test a declaration passes or fails on its way to a role.
 */
interface Matcher
{
    public function matches(ClassFacts $facts): bool;

    /**
     * The test in words, for `sloppy architecture explain`.
     */
    public function describe(): string;
}
