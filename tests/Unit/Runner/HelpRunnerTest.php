<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Help\CommandCatalogue;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\HelpRunner;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;

/**
 * Everything the runner said, as one string to search.
 */
function helpOutput(RecordingRunnerOutput $output): string
{
    return implode("\n", $output->messages());
}

it('names every command on both surfaces', function (): void {
    $output = new RecordingRunnerOutput;

    $exit = (new HelpRunner)->run($output);
    $printed = helpOutput($output);

    expect($exit)->toBe(ExitCode::Success);

    foreach (CommandCatalogue::entries() as $summary) {
        expect($printed)->toContain($summary->cli)
            ->and($printed)->toContain($summary->artisan)
            ->and($printed)->toContain($summary->description)
            ->and($printed)->toContain($summary->when);
    }
});

it('groups the commands under the moment each one is useful', function (): void {
    $output = new RecordingRunnerOutput;

    (new HelpRunner)->run($output);
    $printed = helpOutput($output);

    // Each label appears once: a group printed twice would mean the entries
    // were rendered in catalogue order rather than gathered.
    foreach (['Every day', 'In a pipeline', 'For agents'] as $label) {
        expect(substr_count($printed, $label))->toBe(1);
    }
});

it('says nothing at all when the caller asked for quiet', function (): void {
    $output = new RecordingRunnerOutput(quiet: true);

    $exit = (new HelpRunner)->run($output);

    expect($exit)->toBe(ExitCode::Success)
        ->and($output->messages())->toBe([]);
});
