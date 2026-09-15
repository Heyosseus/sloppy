<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

/**
 * What `sloppy watch` was asked for.
 */
final readonly class WatchOptions implements RunnerOptions
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     * @param  int|null  $top  How many ranked findings the frame lists.
     * @param  int  $interval  Milliseconds between polls of the watched tree.
     */
    public function __construct(
        public array $paths = [],
        public array $rules = [],
        public ?int $minConfidence = null,
        public ?int $top = null,
        public int $interval = 250,
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
     * A dashboard describes; it does not gate. Nothing it shows fails
     * anything, which is why it has no threshold to fail against.
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
