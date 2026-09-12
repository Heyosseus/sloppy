<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

/**
 * The overrides every command accepts.
 *
 * Each of them means the same thing in every command, so the translation into
 * a {@see \Heyosseus\Sloppy\Configuration\Configuration} happens once.
 */
interface RunnerOptions
{
    /** @return list<string> */
    public function paths(): array;

    /** @return list<string> */
    public function rules(): array;

    public function failOn(): ?string;

    public function minConfidence(): ?int;
}
