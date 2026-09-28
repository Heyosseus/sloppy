<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Help\Surface;
use Heyosseus\Sloppy\Output\OutputFormat;

/**
 * What `sloppy` was asked to do.
 */
final readonly class ScanOptions implements RunnerOptions
{
    /**
     * How many defects the triaged report lists, and how many findings a run
     * may have before it is triaged at all.
     */
    public const int TOP = 20;

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
        public bool $all = false,
        public int $top = self::TOP,
        public Surface $surface = Surface::Standalone,
    ) {}

    /**
     * Whether the console report should list every finding rather than
     * triage them. Asking for one rule is asking to see its findings.
     */
    public function listsEverything(): bool
    {
        return $this->all || $this->rules !== [];
    }

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
