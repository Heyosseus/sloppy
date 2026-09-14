<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\SymfonyRunnerOutput;
use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Output\OutputFormat;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Both adapters implement the same port, so both are checked the same way:
 * the four message kinds, a report, and a progress bar opened and closed.
 */
function symfonyAdapter(BufferedOutput $buffer, bool $quiet = false): SymfonyRunnerOutput
{
    $buffer->setVerbosity($quiet ? OutputInterface::VERBOSITY_QUIET : OutputInterface::VERBOSITY_NORMAL);

    return new SymfonyRunnerOutput(new SymfonyStyle(new ArrayInput([]), $buffer));
}

function laravelAdapter(BufferedOutput $buffer, bool $quiet = false): LaravelRunnerOutput
{
    $buffer->setVerbosity($quiet ? OutputInterface::VERBOSITY_QUIET : OutputInterface::VERBOSITY_NORMAL);

    $command = new Command;
    $command->setLaravel(app());
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    return new LaravelRunnerOutput($command);
}

it('reports quietness and drives a progress bar, on the standalone surface', function (): void {
    $buffer = new BufferedOutput;
    $output = symfonyAdapter($buffer);

    expect($output->isQuiet())->toBeFalse()
        ->and(symfonyAdapter(new BufferedOutput, quiet: true)->isQuiet())->toBeTrue();

    $output->startProgress(2);
    $output->advanceProgress();
    $output->finishProgress();

    // Calling finish twice must not blow up: a runner should not have to
    // track whether it opened a bar.
    $output->finishProgress();
    $output->advanceProgress();

    expect($buffer->fetch())->toContain('2/2');
});

it('reports quietness and drives a progress bar, on the Artisan surface', function (): void {
    $buffer = new BufferedOutput;
    $output = laravelAdapter($buffer);

    expect($output->isQuiet())->toBeFalse()
        ->and(laravelAdapter(new BufferedOutput, quiet: true)->isQuiet())->toBeTrue();

    $output->startProgress(2);
    $output->advanceProgress();
    $output->finishProgress();
    $output->finishProgress();
    $output->advanceProgress();

    expect($buffer->fetch())->toContain('2/2');
});

it('writes a machine-readable report raw and a console report line by line', function (): void {
    $buffer = new BufferedOutput;
    $output = symfonyAdapter($buffer);

    $output->report("{\"a\": \"<p>\"}\n", OutputFormat::Json);

    expect($buffer->fetch())->toContain('<p>');

    $output->report("first\nsecond\n", OutputFormat::Console);

    expect($buffer->fetch())->toContain('first');
});

it('says the four kinds of thing a runner can say', function (): void {
    $buffer = new BufferedOutput;
    $output = symfonyAdapter($buffer);

    $output->error('an error');
    $output->info('some information');
    $output->warn('a warning');
    $output->line('a line');
    $output->notice('a notice');

    $written = $buffer->fetch();

    expect($written)->toContain('an error')
        ->and($written)->toContain('some information')
        ->and($written)->toContain('a warning')
        ->and($written)->toContain('a line');
});
