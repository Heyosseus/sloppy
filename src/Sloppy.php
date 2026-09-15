<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Analyzer;
use Heyosseus\Sloppy\Analysis\RuleRegistry;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Baseline\BaselineManager;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Git\DiffAnalyzer;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Git\Git;
use Heyosseus\Sloppy\Scoring\RiskCalculator;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;
use Heyosseus\Sloppy\Support\FileFinder;

/**
 * The package's entry point.
 *
 * Console commands are thin wrappers over this, and anything added later --
 * an editor integration, a GitHub Action, an MCP server -- should be too. All
 * the wiring lives here so there is exactly one place that knows how the parts
 * fit together.
 */
final readonly class Sloppy
{
    /**
     * The one place the package's version is written down.
     *
     * `bin/sloppy` previously carried its own copy and reported `0.2.0`
     * throughout the `0.3.0` release, while the SARIF report identified the
     * tool as `dev`. A version string duplicated across surfaces is a version
     * string that disagrees with itself.
     */
    public const string VERSION = '0.6.0';

    public function __construct(
        public Configuration $configuration,
        private ?RuleRegistry $registry = null,
    ) {}

    public function withConfiguration(Configuration $configuration): self
    {
        return new self($configuration, $this->registry);
    }

    /**
     * Restrict the run to specific rule IDs, as `--rule=SL101` does.
     *
     * @param  list<string>  $ids
     */
    public function onlyRules(array $ids): self
    {
        return new self($this->configuration, $this->rules()->only($ids));
    }

    public function rules(): RuleRegistry
    {
        return $this->registry ?? RuleRegistry::fromConfiguration($this->configuration);
    }

    public function files(): FileFinder
    {
        return new FileFinder($this->configuration);
    }

    public function scores(): ScoreCalculator
    {
        return new ScoreCalculator($this->configuration->score());
    }

    public function risks(): RiskCalculator
    {
        return new RiskCalculator($this->configuration->risk());
    }

    public function analyzer(): Analyzer
    {
        return new Analyzer(
            parser: new Parser,
            registry: $this->rules(),
            calculator: $this->scores(),
            minConfidence: $this->configuration->minConfidence(),
        );
    }

    public function baselines(): BaselineManager
    {
        return new BaselineManager;
    }

    public function git(): Git
    {
        return new Git($this->configuration->basePath);
    }

    /**
     * Analyse the configured paths.
     *
     * @param  (callable(string): void)|null  $onFile
     */
    public function analyze(?callable $onFile = null): AnalysisResult
    {
        return $this->analyzer()->analyze($this->fileMap(), null, $onFile);
    }

    /**
     * Compare the working tree against a git revision.
     */
    public function diff(string $base): DiffReport
    {
        $diff = new DiffAnalyzer(
            git: $this->git(),
            analyzer: $this->analyzer(),
            finder: $this->files(),
            calculator: $this->scores(),
        );

        return $diff->compare($base);
    }

    /**
     * The files a run will cover, as relative path => absolute path.
     *
     * @return array<string, string>
     */
    public function fileMap(): array
    {
        $finder = $this->files();
        $map = [];

        foreach ($finder->find() as $absolute) {
            $map[$finder->relative($absolute)] = $absolute;
        }

        return $map;
    }
}
