<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

/**
 * What `sloppy:baseline` was asked to do.
 *
 * There is no format here: the baseline command reports what it wrote, it does
 * not render findings.
 */
final readonly class BaselineOptions implements RunnerOptions
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     */
    public function __construct(
        public array $paths = [],
        public ?int $minConfidence = null,
        public array $rules = [],
        public bool $force = false,
    ) {}

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    /** @return list<string> */
    public function rules(): array
    {
        return $this->rules;
    }

    public function failOn(): ?string
    {
        return null;
    }

    public function minConfidence(): ?int
    {
        return $this->minConfidence;
    }
}
