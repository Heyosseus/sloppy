<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Contracts\Formatter;
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
    ) {}

    public function for(OutputFormat $format): Formatter
    {
        $risk = $this->sloppy->risks();

        return match ($format) {
            OutputFormat::Json => new JsonFormatter(explainRisk: $this->explainRisk, risk: $risk),
            OutputFormat::Sarif => new SarifFormatter(Sloppy::VERSION),
            OutputFormat::Github => new GithubFormatter,
            OutputFormat::Gitlab => new GitlabFormatter,
            OutputFormat::Rector => new RectorFormatter,
            OutputFormat::Markdown => new MarkdownFormatter(risk: $risk, explainRisk: $this->explainRisk),
            OutputFormat::Console => new ConsoleFormatter(
                explain: $this->explain,
                failOn: $this->failOn,
                explainRisk: $this->explainRisk,
                risk: $risk,
            ),
        };
    }
}
