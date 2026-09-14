<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

/**
 * What `sloppy health` was asked for.
 */
final readonly class HealthOptions implements RunnerOptions
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     */
    public function __construct(
        public array $paths = [],
        public array $rules = [],
        public ?int $minConfidence = null,
        public bool $json = false,
        public bool $fresh = false,
        public ?int $top = null,
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

    /**
     * A snapshot is a description, not a gate, so nothing about it fails.
     */
    public function failOn(): ?string
    {
        return null;
    }

    public function minConfidence(): ?int
    {
        return $this->minConfidence;
    }
}
