<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Output\OutputFormat;

/**
 * What `sloppy:diff` was asked to do.
 *
 * There is no `noBaseline` here: diff mode separates new findings from
 * inherited ones itself, so a baseline would be answering a question that has
 * already been answered.
 */
final readonly class DiffOptions implements RunnerOptions
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     */
    public function __construct(
        public string $base = 'HEAD',
        public array $paths = [],
        public OutputFormat $format = OutputFormat::Console,
        public ?string $failOn = null,
        public ?int $minConfidence = null,
        public array $rules = [],
        public bool $explain = false,
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
