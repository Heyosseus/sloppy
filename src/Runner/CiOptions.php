<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Output\OutputFormat;

/**
 * What `sloppy ci` was asked to do.
 *
 * Almost every field is nullable because almost every field has a right
 * answer the surrounding CI system already knows. Three lines of YAML is the
 * target, so anything left unset is worked out rather than demanded.
 */
final readonly class CiOptions implements RunnerOptions
{
    /**
     * @param  string|null  $base  Revision to compare against; null to read it from the CI environment.
     * @param  OutputFormat|null  $format  Report shape; null to use the provider's.
     * @param  string|null  $report  File to write the machine-readable report to; null for standard output.
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     */
    public function __construct(
        public ?string $base = null,
        public array $paths = [],
        public array $rules = [],
        public ?string $failOn = null,
        public ?int $minConfidence = null,
        public ?OutputFormat $format = null,
        public ?string $report = null,
        public bool $summary = true,
        public bool $scan = false,
        public bool $noBaseline = false,
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
