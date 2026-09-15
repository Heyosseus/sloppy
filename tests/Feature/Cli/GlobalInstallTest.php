<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * A project that has never heard of Sloppy, and Sloppy installed somewhere
 * else entirely.
 *
 * `composer global require` puts the package at
 * `~/.composer/vendor/heyosseus/sloppy`, which is the layout rebuilt here: the
 * binary three directories below an autoloader, and a project that shares
 * nothing with it. That is the one arrangement `bin/sloppy`'s first autoload
 * probe exists for, and nothing else in the suite walks it.
 *
 * @return array{0: string, 1: string} The installed binary, and a directory deep inside the project.
 */
function globallyInstalled(): array
{
    $root = tempProject([
        'project/composer.json' => json_encode(['autoload' => ['psr-4' => ['Acme\\' => 'src/']]], JSON_THROW_ON_ERROR),
        'project/src/Bad.php' => godMethodSource(),
        'project/src/deeply/nested/.gitkeep' => '',
        // Composer's own autoloader, where a global install would put it.
        'global/vendor/autoload.php' => sprintf(
            "<?php\n\nrequire %s;\n",
            var_export(str_replace('\\', '/', dirname(__DIR__, 3)).'/vendor/autoload.php', true),
        ),
    ]);

    $binary = $root.'/global/vendor/heyosseus/sloppy/bin/sloppy';

    mkdir(dirname($binary), 0o777, true);
    copy(dirname(__DIR__, 3).'/bin/sloppy', $binary);

    return [$binary, $root.'/project/src/deeply/nested'];
}

it('analyses a project it was never installed into', function (): void {
    [$binary, $inside] = globallyInstalled();

    $process = new Process([PHP_BINARY, $binary, 'scan', '--format=json', '--fail-on=never'], $inside);
    $process->run();

    /** @var array{findings: list<array{rule: string}>, summary: array{files: int}} $report */
    $report = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(0)
        // It walked up four directories to the composer.json, with no --project.
        ->and($report['summary']['files'])->toBe(1)
        // And found src/ from the PSR-4 roots, with no configuration file.
        ->and(array_column($report['findings'], 'rule'))->toContain('SL101');

    removeTree(dirname($binary, 5));
});

it('reports its own version from wherever it was installed', function (): void {
    [$binary, $inside] = globallyInstalled();

    $process = new Process([PHP_BINARY, $binary, '--version'], $inside);
    $process->run();

    expect($process->getOutput())->toContain(Heyosseus\Sloppy\Sloppy::VERSION);

    removeTree(dirname($binary, 5));
});
