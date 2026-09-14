<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\BlastRadiusEnricher;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Cli\ProjectLocator;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Git\Git;
use Heyosseus\Sloppy\Runner\DiffOptions;
use Heyosseus\Sloppy\Runner\DiffRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;

it('answers with nothing for every file it was asked about outside a repository', function (): void {
    $root = tempProject(['composer.json' => '{}', 'src/Fine.php' => "<?php\n\nclass Fine {}\n"]);

    expect((new Git($root))->showFiles('HEAD', ['src/Fine.php', 'src/Other.php']))
        ->toBe(['src/Fine.php' => null, 'src/Other.php' => null]);

    removeTree($root);
});

it('reports that git could not be run at all', function (): void {
    // A working directory that does not exist: the process cannot start, so
    // git is not available *here* even though it is installed.
    $sloppy = new Sloppy(Configuration::fromArray([], sys_get_temp_dir().'/sloppy-not-a-directory-'.bin2hex(random_bytes(4))));
    $output = new RecordingRunnerOutput;

    $code = (new DiffRunner)->run($sloppy, new DiffOptions(base: 'HEAD'), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages())->toContain('error: git is not available on PATH, so diff mode cannot run.');
});

it('climbs no further than the root of the filesystem', function (): void {
    $root = sys_get_temp_dir();

    while (dirname($root) !== $root) {
        $root = dirname($root);
    }

    // There is nowhere above the root to look, so the walk stops rather than
    // circling on a directory that is its own parent.
    expect(fn (): string => (new ProjectLocator)->locate(null, $root))
        ->toThrow(RuntimeException::class, 'No composer.json found in');
});

it('matches a one-line class by its own line, having no span to measure', function (): void {
    $index = ProjectIndex::build([
        (new Parser)->parse('app/Tiny.php', "<?php\n\nnamespace App;\n\nclass Tiny {}\n"),
    ]);

    $onIt = (new BlastRadiusEnricher($index))->enrich([finding(file: 'app/Tiny.php', line: 5)]);
    $offIt = (new BlastRadiusEnricher($index))->enrich([finding(file: 'app/Tiny.php', line: 2)]);

    expect($onIt[0]->metrics)->toBeArray()
        ->and($offIt[0]->metrics)->toBeArray();
});

it('prefers the smaller of two classes claiming the same lines', function (): void {
    // Two files that report the same path -- which is what an index built
    // from a generated file and its source looks like -- so both classes
    // contain the reported line and the tie-break has to choose.
    $index = ProjectIndex::build([
        (new Parser)->parse('app/Same.php', "<?php\n\nnamespace App;\n\nclass Narrow\n{\n    public function a(): void\n    {\n    }\n}\n"),
        (new Parser)->parse('app/Same.php', "<?php\n\nnamespace App;\n\nclass Wide\n{\n    public function a(): void\n    {\n    }\n\n    public function b(): void\n    {\n    }\n}\n"),
    ]);

    $enriched = (new BlastRadiusEnricher($index))->enrich([finding(file: 'app/Same.php', line: 7)]);

    expect($enriched[0]->metrics)->toBeArray();
});
