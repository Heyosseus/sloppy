<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;

it('records every message kind with its label', function (): void {
    $output = new RecordingRunnerOutput;

    $output->error('broke');
    $output->info('fine');
    $output->warn('careful');
    $output->line('plain');

    expect($output->messages())->toBe([
        'error: broke',
        'info: fine',
        'warn: careful',
        'line: plain',
    ]);
});

it('keeps the report and its format verbatim', function (): void {
    $output = new RecordingRunnerOutput;

    $output->report("{\"schema\":1}\n", OutputFormat::Json);

    expect($output->reports())->toBe([['json', "{\"schema\":1}\n"]]);
});

it('records a progress run as start, advances and finish', function (): void {
    $output = new RecordingRunnerOutput;

    $output->startProgress(3);
    $output->advanceProgress();
    $output->advanceProgress();
    $output->finishProgress();

    expect($output->progressEvents())->toBe(['start:3', 'advance', 'advance', 'finish']);
});
