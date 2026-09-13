<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Output\OutputFormat;

/**
 * What `sloppy` was asked to do.
 */
final readonly class ScanOptions implements RunnerOptions
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     */
    public function __construct(
        public array $paths = [],
        public OutputFormat $format = OutputFormat::Console,
        public ?string $failOn = null,
        public ?int $minConfidence = null,
        public array $rules = [],
        public bool $explain = false,
        public bool $noBaseline = false,
        public bool $explainRisk = false,
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
        return $this->failOn;
    }

    public function minConfidence(): ?int
    {
        return $this->minConfidence;
    }
}
