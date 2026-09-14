<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Integrations\Tooling\ProcessToolRunner;
use Heyosseus\Sloppy\Integrations\Tooling\ToolDetector;
use Heyosseus\Sloppy\Integrations\Tooling\ToolResult;

it('finds the binaries composer installed', function (): void {
    $root = tempProject([
        'vendor/bin/pint' => '#!/usr/bin/env php',
        'vendor/bin/rector' => '#!/usr/bin/env php',
    ]);

    $detector = new ToolDetector($root);

    expect($detector->hasPint())->toBeTrue()
        ->and($detector->hasRector())->toBeTrue()
        ->and($detector->pint())->toBe($root.'/vendor/bin/pint')
        ->and($detector->rector())->toBe($root.'/vendor/bin/rector')
        ->and($detector->find('php-cs-fixer'))->toBeNull();

    removeTree($root);
});

it('prefers the Windows shim when there is one', function (): void {
    $root = tempProject([
        'vendor/bin/pint' => '#!/usr/bin/env php',
        'vendor/bin/pint.bat' => '@php "%~dp0pint" %*',
    ]);

    expect((new ToolDetector($root))->pint())->toBe($root.'/vendor/bin/pint.bat');

    removeTree($root);
});

it('says nothing is installed when nothing is', function (): void {
    $root = tempProject(['composer.json' => '{}']);
    $detector = new ToolDetector($root);

    expect($detector->hasPint())->toBeFalse()
        ->and($detector->hasRector())->toBeFalse()
        ->and($detector->pint())->toBeNull();

    removeTree($root);
});

it('looks wherever the project keeps its binaries', function (): void {
    $root = tempProject(['tools/rector' => '#!/usr/bin/env php']);

    expect((new ToolDetector($root, 'tools'))->rector())->toBe($root.'/tools/rector')
        ->and((new ToolDetector($root))->rector())->toBeNull();

    removeTree($root);
});

it('reports what a tool said and whether it worked', function (): void {
    $success = new ToolResult('pint', 0, "1 file fixed\n");
    $failure = new ToolResult('rector', 1, implode("\n", array_map(static fn (int $i): string => 'line '.$i, range(1, 30))));

    expect($success->successful())->toBeTrue()
        ->and($success->tail())->toBe('1 file fixed')
        ->and($failure->successful())->toBeFalse()
        ->and($failure->tail())->toContain('line 30')
        ->and($failure->tail())->not->toContain('line 18')
        ->and(explode(PHP_EOL, $failure->tail()))->toHaveCount(12)
        ->and(explode(PHP_EOL, $failure->tail(3)))->toHaveCount(3)
        ->and(explode(PHP_EOL, $failure->tail(0)))->toHaveCount(1);
});

it('really runs a process and keeps its output', function (): void {
    $root = tempProject(['composer.json' => '{}']);

    $result = (new ProcessToolRunner(timeout: 30.0))->run(
        'php',
        [PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(3);'],
        $root,
    );

    expect($result->tool)->toBe('php')
        ->and($result->exitCode)->toBe(3)
        ->and($result->successful())->toBeFalse()
        ->and($result->output)->toContain('out')
        ->and($result->output)->toContain('err');

    removeTree($root);
});

it('reports a binary that could not be started as a failure', function (): void {
    $root = tempProject(['composer.json' => '{}']);

    $result = (new ProcessToolRunner(timeout: 10.0))->run('nope', [$root.'/not-a-binary'], $root);

    expect($result->successful())->toBeFalse();

    removeTree($root);
});
