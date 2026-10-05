<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Analysis\TierMap;
use Heyosseus\Sloppy\Contracts\Formatter;
use Heyosseus\Sloppy\Help\Surface;
use Heyosseus\Sloppy\Scoring\RiskCalculator;
use Heyosseus\Sloppy\Sloppy;

/**
 * Which report a format means.
 *
 * Two runners now render a full run -- `sloppy` and `sloppy ci` in scan mode
 * -- and a format that behaved differently depending on which command asked
 * for it would be a bug nobody could see from either command's source.
 */
final readonly class FormatterFactory
{
    public function __construct(
        private Sloppy $sloppy,
        private bool $explain = false,
        private bool $explainRisk = false,
        private ?Severity $failOn = null,
        private bool $all = true,
        private int $top = 20,
        private Surface $surface = Surface::Standalone,
        private bool $hasBaseline = false,
        private ?string $pathPrefix = null,
    ) {}

    public function for(OutputFormat $format): Formatter
    {
        $risk = $this->sloppy->risks();

        return match ($format) {
            OutputFormat::Json => new JsonFormatter(explainRisk: $this->explainRisk, risk: $risk, tiers: $this->tiers()),
            OutputFormat::Sarif => new SarifFormatter(Sloppy::VERSION, $this->pathPrefix()),
            OutputFormat::Github => new GithubFormatter($this->pathPrefix()),
            OutputFormat::Gitlab => new GitlabFormatter(pathPrefix: $this->pathPrefix()),
            OutputFormat::Rector => new RectorFormatter,
            OutputFormat::Markdown => new MarkdownFormatter(risk: $risk, explainRisk: $this->explainRisk),
            OutputFormat::Console => $this->console($risk),
        };
    }

    /**
     * The full report, or the triage of it when the caller asked for one --
     * which only `sloppy scan` does. Every other command that renders a run
     * keeps listing everything, because a CI log is searched, not read.
     */
    private function console(RiskCalculator $risk): Formatter
    {
        $console = new ConsoleFormatter(
            explain: $this->explain,
            failOn: $this->failOn,
            explainRisk: $this->explainRisk,
            risk: $risk,
        );

        return $this->all ? $console : new TriageFormatter(
            console: $console,
            tiers: $this->tiers(),
            risk: $risk,
            surface: $this->surface,
            top: $this->top,
            hasBaseline: $this->hasBaseline,
        );
    }

    /**
     * The project's path inside its repository, for the formats a forge
     * reads: they name files from the repository root. Nothing outside git.
     */
    public function pathPrefix(): string
    {
        return $this->pathPrefix ?? $this->sloppy->git()->prefix();
    }

    public function tiers(): TierMap
    {
        return new TierMap($this->sloppy->configuration);
    }
}
