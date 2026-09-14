<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

/**
 * What `sloppy fix` was asked to do.
 *
 * There is no `failOn` here that a user can set: fixing is not a gate, and a
 * command that rewrote code and then failed the build for the findings it
 * could not rewrite would be reporting the same debt twice.
 */
final readonly class FixOptions implements RunnerOptions
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     */
    public function __construct(
        public array $paths = [],
        public array $rules = [],
        public ?int $minConfidence = null,
        public bool $dryRun = false,
        public bool $withRector = true,
        public bool $withPint = true,
        public bool $keepConfig = false,
        public string $configFile = 'rector-sloppy.php',
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
