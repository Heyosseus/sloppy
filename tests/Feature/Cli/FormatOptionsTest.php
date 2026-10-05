<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\SloppyApplication;
use Heyosseus\Sloppy\Console\Commands\SloppyCiCommand;
use Heyosseus\Sloppy\Console\Commands\SloppyCommand;
use Heyosseus\Sloppy\Console\Commands\SloppyDiffCommand;
use Heyosseus\Sloppy\Output\OutputFormat;

/**
 * Every format a command accepts is one its help names, and every flag the
 * documentation promises is one the command accepts -- on both surfaces.
 */
function helpFor(string $command, string $option): string
{
    return (new SloppyApplication('test'))->find($command)->getDefinition()->getOption($option)->getDescription();
}

/**
 * @param  class-string  $class
 */
function artisanSignature(string $class): string
{
    return (string) (new ReflectionProperty($class, 'signature'))->getDefaultValue();
}

it('names every format in the --format help of scan, diff and ci', function (): void {
    foreach (OutputFormat::cases() as $format) {
        foreach (['scan', 'diff', 'ci'] as $command) {
            expect(helpFor($command, 'format'))->toContain($format->value);
        }

        foreach ([SloppyCommand::class, SloppyDiffCommand::class, SloppyCiCommand::class] as $class) {
            expect(artisanSignature($class))->toMatch('/\{--format=[^:}]*:[^}]*\b'.$format->value.'\b/');
        }
    }
});

it('accepts --explain-risk on scan, diff, review and ci, and the new ci and diff flags', function (): void {
    $application = new SloppyApplication('test');

    foreach (['scan', 'diff', 'review', 'ci'] as $command) {
        expect($application->find($command)->getDefinition()->hasOption('explain-risk'))->toBeTrue();
    }

    expect($application->find('diff')->getDefinition()->hasOption('merge-base'))->toBeTrue()
        ->and($application->find('ci')->getDefinition()->hasOption('no-merge-base'))->toBeTrue()
        ->and($application->find('ci')->getDefinition()->hasOption('allow-parse-errors'))->toBeTrue()
        ->and(artisanSignature(SloppyDiffCommand::class))->toContain('{--explain-risk')
        ->and(artisanSignature(SloppyDiffCommand::class))->toContain('{--merge-base')
        ->and(artisanSignature(SloppyCiCommand::class))->toContain('{--explain-risk')
        ->and(artisanSignature(SloppyCiCommand::class))->toContain('{--no-merge-base')
        ->and(artisanSignature(SloppyCiCommand::class))->toContain('{--allow-parse-errors');
});
