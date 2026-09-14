<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\SloppyApplication;
use Symfony\Component\Console\Input\InputOption;

/**
 * The repository root, from a test file two levels below it.
 */
function repositoryRoot(): string
{
    return dirname(__DIR__, 2);
}

function actionManifest(): string
{
    return (string) file_get_contents(repositoryRoot().'/action.yml');
}

it('ships a GitHub Action at the root, where the marketplace looks for it', function (): void {
    $action = actionManifest();

    expect(is_file(repositoryRoot().'/action.yml'))->toBeTrue()
        ->and($action)->toContain("name: 'Sloppy Agent Review'")
        ->and($action)->toContain("using: 'composite'")
        ->and($action)->toContain('branding:');
});

it('takes the three-line setup the README promises', function (): void {
    $action = actionManifest();

    // The documented usage is `uses:` plus `with: diff-branch:` and nothing
    // else, so those two must stay optional-with-a-default and named exactly
    // this.
    expect($action)->toContain('diff-branch:')
        ->and($action)->toContain('fail-on:')
        ->and($action)->toContain("default: 'high'")
        ->and($action)->toContain('required: false');
});

it('runs the command it says it runs, with options that exist', function (): void {
    $action = actionManifest();
    $command = (new SloppyApplication('test'))->get('ci');

    expect($action)->toContain('ci "${args[@]}"');

    foreach (['base', 'fail-on', 'format', 'report', 'min-confidence', 'scan', 'no-summary', 'path', 'rule'] as $option) {
        expect($command->getDefinition()->hasOption($option))
            ->toBeTrue(sprintf('The action passes --%s, which `sloppy ci` does not accept.', $option));
    }

    expect($command->getDefinition()->getOption('path')->isArray())->toBeTrue()
        ->and($command->getDefinition()->getOption('scan')->acceptValue())->toBeFalse();
});

it('reads back every output the runner writes', function (): void {
    $action = actionManifest();

    // CiRunner writes these names into $GITHUB_OUTPUT; an action that declared
    // a different set would hand the workflow an empty string and no error.
    foreach (['score', 'score-delta', 'findings', 'new-findings', 'resolved-findings', 'status', 'mode'] as $output) {
        expect($action)->toContain(sprintf('%s:', $output))
            ->and($action)->toContain(sprintf('steps.sloppy.outputs.%s', $output));
    }
});

it('fetches the base branch, because a shallow checkout does not have it', function (): void {
    expect(actionManifest())->toContain('git fetch --no-tags --depth=50 origin');
});

it('ships a GitLab template that produces a Code Quality artifact', function (): void {
    $template = (string) file_get_contents(repositoryRoot().'/resources/ci/gitlab-ci.yml');

    expect($template)->toContain('codequality: $SLOPPY_REPORT')
        ->and($template)->toContain('--format=gitlab')
        ->and($template)->toContain('--report="$SLOPPY_REPORT"')
        ->and($template)->toContain('SLOPPY_FAIL_ON: high')
        ->and($template)->toContain('gl-code-quality-report.json');
});

it('ships both binaries and the Pest expectations', function (): void {
    /** @var array{bin: list<string>, autoload: array{files: list<string>}, suggest: array<string, string>} $manifest */
    $manifest = json_decode(
        (string) file_get_contents(repositoryRoot().'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($manifest['bin'])->toBe(['bin/sloppy', 'bin/sloppy-mcp'])
        ->and($manifest['autoload']['files'])->toBe(['pest/Expectations.php'])
        ->and($manifest['suggest'])->toHaveKey('rector/rector')
        ->and($manifest['suggest'])->toHaveKey('laravel/pint')
        ->and($manifest['suggest'])->toHaveKey('filament/filament')
        ->and(is_file(repositoryRoot().'/bin/sloppy-mcp'))->toBeTrue()
        ->and(is_file(repositoryRoot().'/pest/Expectations.php'))->toBeTrue();
});

it('starts the MCP server from its own binary without an argument', function (): void {
    $binary = (string) file_get_contents(repositoryRoot().'/bin/sloppy-mcp');

    expect($binary)->toStartWith('#!/usr/bin/env php')
        ->and($binary)->toContain('StdioTransport')
        ->and($binary)->toContain("ini_set('display_errors', 'stderr')")
        ->and($binary)->toContain('php://stdin');
});

it('documents every option the action passes as an input', function (): void {
    $action = actionManifest();

    foreach (['paths', 'rules', 'min-confidence', 'format', 'report', 'scan', 'summary', 'working-directory', 'php-version', 'version'] as $input) {
        expect($action)->toContain(sprintf('  %s:', $input));
    }

    expect(InputOption::VALUE_NONE)->toBeInt();
});
