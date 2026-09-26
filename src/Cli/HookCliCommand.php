<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Agent\HookEvent;
use Heyosseus\Sloppy\Agent\HookPayload;
use Heyosseus\Sloppy\Configuration\ConfigurationLoader;
use Heyosseus\Sloppy\Runner\HookOutcome;
use Heyosseus\Sloppy\Runner\HookRunner;
use Heyosseus\Sloppy\Sloppy;
use SplFileObject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `sloppy hook <event>` -- what an agent's hooks call; not for people.
 *
 * It is hidden because nobody types it: `sloppy agents install` writes it
 * into the agent's settings. It speaks the hook protocol rather than the
 * console's -- a payload on standard input, and exit 2 with standard error for
 * findings the model should act on -- so it prints no project-root notice and
 * no styling, and anything that goes wrong before the analysis has even
 * started exits 0 like everything else that is not a finding.
 */
#[AsCommand(name: 'hook', description: 'Run Sloppy from inside a coding agent\'s hooks', hidden: true)]
final class HookCliCommand extends CliCommandBase
{
    public function __construct(private readonly ?SplFileObject $stdin = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('event', InputArgument::REQUIRED, 'post-edit or stop')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project directory (default: the one the agent is working in)')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a sloppy configuration file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outcome = $this->outcome($input);

        if ($outcome->stdout !== '') {
            $output->write($outcome->stdout, false, OutputInterface::OUTPUT_RAW);
        }

        if ($outcome->stderr !== '') {
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errors->write($outcome->stderr, false, OutputInterface::OUTPUT_RAW);
        }

        return $outcome->exitCode();
    }

    private function outcome(InputInterface $input): HookOutcome
    {
        try {
            $event = HookEvent::parse((string) $input->getArgument('event'));
            $payload = HookPayload::fromJson($this->readPayload());
            $root = (new ProjectLocator)->locate($this->stringOption($input, 'project'), $payload->cwd ?? (string) getcwd());
            $configuration = (new ConfigurationLoader($root))->load($this->stringOption($input, 'config'));

            return (new HookRunner)->run(new Sloppy($configuration), $event, $payload);
        } catch (Throwable $exception) {
            return HookOutcome::pass(sprintf("Sloppy: the check did not run (%s).\n", $exception->getMessage()));
        }
    }

    private function readPayload(): string
    {
        $stream = $this->stdin ?? new SplFileObject('php://stdin');
        $payload = '';

        while (! $stream->eof()) {
            $payload .= $stream->fgets();
        }

        return $payload;
    }
}
