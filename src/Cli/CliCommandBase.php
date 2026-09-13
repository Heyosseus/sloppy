<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Configuration\ConfigurationLoader;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\DiffOptions;
use Heyosseus\Sloppy\Runner\DiffRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\RunnerOutput;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Support\StringListOption;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The options every standalone command shares, and the bootstrapping Laravel
 * would otherwise have done.
 */
abstract class CliCommandBase extends Command
{
    protected function configureSharedOptions(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project directory to analyse (default: the nearest one above the working directory)')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a sloppy configuration file')
            ->addOption('path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Analyse these paths instead of the configured ones')
            ->addOption('min-confidence', null, InputOption::VALUE_REQUIRED, 'Drop findings below this confidence (0-100)')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only these rule IDs, e.g. --rule=SL101');
    }

    /**
     * Which project is being analysed and where its configuration came from
     * are both invisible unless we say so -- a scan that covered the wrong
     * tree looks identical to one that covered the right one. `notice()`
     * carries this rather than `info()` so it never lands ahead of a
     * machine-readable report.
     */
    protected function sloppy(InputInterface $input, RunnerOutput $output): Sloppy
    {
        $root = (new ProjectLocator)->locate($this->stringOption($input, 'project'), (string) getcwd());
        $loader = new ConfigurationLoader($root);
        $configuration = $loader->load($this->stringOption($input, 'config'));

        $output->notice(sprintf('Project root: %s (config: %s).', $root, $loader->source()));

        return new Sloppy($configuration);
    }

    protected function runnerOutput(InputInterface $input, OutputInterface $output): SymfonyRunnerOutput
    {
        return new SymfonyRunnerOutput(new SymfonyStyle($input, $output));
    }

    protected function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function intOption(InputInterface $input, string $name): ?int
    {
        $value = $this->stringOption($input, $name);

        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('--%s must be a number.', $name));
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    protected function stringListOption(InputInterface $input, string $name): array
    {
        return StringListOption::from($input->getOption($name));
    }

    /**
     * The `DiffOptions` shared by `diff` and `review` -- same base revision,
     * paths, format, fail-on threshold, confidence floor and rule filter.
     * Only which explanation flag was read and whether the reading-order
     * presentation was asked for differ between the two commands.
     */
    protected function diffOptionsFrom(
        InputInterface $input,
        bool $explain = false,
        bool $explainRisk = false,
        bool $review = false,
    ): DiffOptions {
        $base = $input->getArgument('base');

        return new DiffOptions(
            base: is_string($base) && trim($base) !== '' ? trim($base) : 'HEAD',
            paths: $this->stringListOption($input, 'path'),
            format: OutputFormat::parse($this->stringOption($input, 'format') ?? 'console'),
            failOn: $this->stringOption($input, 'fail-on'),
            minConfidence: $this->intOption($input, 'min-confidence'),
            rules: $this->stringListOption($input, 'rule'),
            explain: $explain,
            explainRisk: $explainRisk,
            review: $review,
        );
    }

    /**
     * `diff` and `review` are the same run of {@see DiffRunner} against the
     * same options, presented two ways -- this is the whole of both commands.
     * Living here rather than duplicated in each keeps them from drifting
     * apart the way {@see Heyosseus\Sloppy\Rules\Php\CopyPasteDriftRule} warns
     * a maintained copy eventually does.
     */
    protected function runDiff(
        InputInterface $input,
        OutputInterface $output,
        bool $explain = false,
        bool $explainRisk = false,
        bool $review = false,
    ): int {
        $runnerOutput = $this->runnerOutput($input, $output);

        try {
            $sloppy = $this->sloppy($input, $runnerOutput);
            $options = $this->diffOptionsFrom($input, explain: $explain, explainRisk: $explainRisk, review: $review);
        } catch (Throwable $exception) {
            $runnerOutput->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        return (new DiffRunner)->run($sloppy, $options, $runnerOutput)->value;
    }
}
