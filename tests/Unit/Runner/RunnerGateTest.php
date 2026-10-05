<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ci\CiEnvironment;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\CiOptions;
use Heyosseus\Sloppy\Runner\CiRunner;
use Heyosseus\Sloppy\Runner\DiffOptions;
use Heyosseus\Sloppy\Runner\DiffRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\ScanOptions;
use Heyosseus\Sloppy\Runner\ScanRunner;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

/**
 * The exits a pipeline relies on: no green check for a run that checked
 * nothing, and no format quietly swapped for another.
 */
function gateSwallowing(string $class): string
{
    return "<?php\n\nnamespace App;\n\nclass {$class}\n{\n    public function show(int \$id)\n    {\n        try {\n            return \$this->records->find(\$id);\n        } catch (\\Throwable \$e) {\n            return null;\n        }\n    }\n}\n";
}

function gateSloppy(string $root, array $config = []): Sloppy
{
    return new Sloppy(Configuration::fromArray([...['paths' => ['app'], 'fail_on' => 'high'], ...$config], $root));
}

describe('a path that does not exist', function (): void {
    it('fails a scan instead of warning and passing', function (): void {
        $root = tempProject(['app/A.php' => "<?php\n\nclass A\n{\n}\n"]);
        $output = new RecordingRunnerOutput;

        $code = (new ScanRunner)->run(gateSloppy($root), new ScanOptions(paths: ['apps']), $output);

        expect($code)->toBe(ExitCode::Error)
            ->and($output->messages()[0])->toContain('Path [apps] does not exist');

        removeTree($root);
    });

    it('fails a scan whose configured paths are all missing', function (): void {
        $root = tempProject(['src/A.php' => "<?php\n\nclass A\n{\n}\n"]);
        $output = new RecordingRunnerOutput;

        $code = (new ScanRunner)->run(gateSloppy($root, ['paths' => ['apps']]), new ScanOptions, $output);

        expect($code)->toBe(ExitCode::Error)
            ->and($output->messages()[0])->toContain('None of the configured paths exist: apps');

        removeTree($root);
    });

    it('fails ci', function (): void {
        $root = tempProject(['app/A.php' => "<?php\n\nclass A\n{\n}\n"]);
        $output = new RecordingRunnerOutput;

        $code = (new CiRunner(new CiEnvironment([])))->run(gateSloppy($root), new CiOptions(paths: ['apps'], scan: true), $output);

        expect($code)->toBe(ExitCode::Error)
            ->and($output->messages()[0])->toContain('Path [apps] does not exist');

        removeTree($root);
    });
});

describe('a project nothing of which parses', function (): void {
    it('has no score to report from a scan, even with --fail-on=info', function (): void {
        $root = tempProject(['app/Bad.php' => "<?php\nclass {\n"]);
        $output = new RecordingRunnerOutput;

        $code = (new ScanRunner)->run(gateSloppy($root), new ScanOptions(format: OutputFormat::Json, failOn: 'info'), $output);

        expect($code)->toBe(ExitCode::Error)
            ->and($output->reports())->toBe([])
            ->and($output->messages()[0])->toContain('None of the 1 PHP file(s) could be parsed');

        removeTree($root);
    });

    it('fails ci', function (): void {
        $root = tempProject(['app/Bad.php' => "<?php\nclass {\n"]);
        $output = new RecordingRunnerOutput;

        $code = (new CiRunner(new CiEnvironment([])))->run(gateSloppy($root), new CiOptions(failOn: 'info', scan: true), $output);

        expect($code)->toBe(ExitCode::Error)
            ->and($output->reports())->toBe([]);

        removeTree($root);
    });
});

it('fails ci on a file that does not parse, unless told to accept it', function (): void {
    $root = tempProject([
        'app/Fine.php' => "<?php\n\nclass Fine\n{\n}\n",
        'app/Bad.php' => "<?php\nclass {\n",
    ]);

    $failing = new RecordingRunnerOutput;
    $failed = (new CiRunner(new CiEnvironment([])))->run(gateSloppy($root), new CiOptions(scan: true), $failing);

    $allowed = (new CiRunner(new CiEnvironment([])))->run(gateSloppy($root), new CiOptions(scan: true, allowParseErrors: true), new RecordingRunnerOutput);

    expect($failed)->toBe(ExitCode::Error)
        ->and($failing->reportBody())->toContain('/100')
        ->and(implode("\n", $failing->messages()))->toContain('1 file(s) could not be analysed, starting with app/Bad.php')
        ->and($allowed)->toBe(ExitCode::Success);

    removeTree($root);
});

describe('in a git repository', function (): void {
    beforeEach(function (): void {
        if (! TempRepository::gitIsAvailable()) {
            $this->markTestSkipped('git is not available on this machine.');
        }
    });

    it('renders every format diff accepts, rather than falling back to the console', function (): void {
        $repository = TempRepository::create()
            ->write('app/Fine.php', "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n")
            ->commit('first')
            ->write('app/Bad.php', gateSwallowing('Bad'));

        $bodies = [];

        foreach (OutputFormat::cases() as $format) {
            $output = new RecordingRunnerOutput;

            expect((new DiffRunner)->run(gateSloppy($repository->path, ['fail_on' => 'never']), new DiffOptions(format: $format), $output))
                ->toBe(ExitCode::Success);

            $bodies[$format->value] = $output->reportBody();
        }

        expect($bodies['github'])->toContain('::error file=app/Bad.php')
            ->and($bodies['github'])->toContain('::notice title=Sloppy diff::')
            ->and($bodies['sarif'])->toContain('"ruleId": "SL107"')
            ->and($bodies['gitlab'])->toContain('"check_name": "SL107"')
            ->and($bodies['rector'])->toContain('RectorConfig')
            ->and($bodies['markdown'])->toStartWith('## ')
            ->and($bodies['markdown'])->not->toContain('<options=')
            ->and($bodies['json'])->toContain('"mode": "diff"');

        $repository->remove();
    });

    it('explains risk in a diff', function (): void {
        $repository = TempRepository::create()
            ->write('app/Fine.php', "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n")
            ->commit('first')
            ->write('app/Bad.php', gateSwallowing('Bad'));

        $json = new RecordingRunnerOutput;
        (new DiffRunner)->run(gateSloppy($repository->path), new DiffOptions(format: OutputFormat::Json, explainRisk: true), $json);

        $console = new RecordingRunnerOutput;
        (new DiffRunner)->run(gateSloppy($repository->path), new DiffOptions(explainRisk: true), $console);

        /** @var array{new: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($json->reportBody(), true, 512, JSON_THROW_ON_ERROR);

        expect($decoded['new'][0])->toHaveKeys(['risk', 'tier', 'risk_factors', 'risk_arithmetic'])
            ->and($console->reportBody())->toContain(' x ');

        $repository->remove();
    });

    it('compares against the merge base when asked', function (): void {
        $repository = TempRepository::create()
            ->write('app/Fine.php', "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n")
            ->commit('first');
        $repository->git(['checkout', '-q', '-b', 'feature']);
        $repository->write('app/Mine.php', "<?php\n\nnamespace App;\n\nclass Mine\n{\n}\n")->commit('feature');
        $repository->git(['checkout', '-q', 'main']);
        // main fixes nothing and breaks something after the fork; against its
        // tip, the feature branch would be credited with "resolving" it.
        $repository->write('app/Later.php', gateSwallowing('Later'))->commit('main moves on');
        $repository->git(['checkout', '-q', 'feature']);

        $tip = new RecordingRunnerOutput;
        (new DiffRunner)->run(gateSloppy($repository->path), new DiffOptions(base: 'main', format: OutputFormat::Json), $tip);

        $fork = new RecordingRunnerOutput;
        (new DiffRunner)->run(gateSloppy($repository->path), new DiffOptions(base: 'main', format: OutputFormat::Json, mergeBase: true), $fork);

        $ci = new RecordingRunnerOutput;
        (new CiRunner(new CiEnvironment([])))->run(gateSloppy($repository->path), new CiOptions(base: 'main', format: OutputFormat::Json), $ci);

        /** @var array{summary: array{resolved: int}} $againstTip */
        $againstTip = json_decode($tip->reportBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{summary: array{resolved: int}, changed_files: list<array{path: string}>} $againstFork */
        $againstFork = json_decode($fork->reportBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{summary: array{resolved: int}, changed_files: list<array{path: string}>} $inCi */
        $inCi = json_decode($ci->reportBody(), true, 512, JSON_THROW_ON_ERROR);

        expect($againstTip['summary']['resolved'])->toBe(1)
            ->and($againstFork['summary']['resolved'])->toBe(0)
            ->and(array_column($againstFork['changed_files'], 'path'))->toBe(['app/Mine.php'])
            ->and($inCi['summary']['resolved'])->toBe(0)
            ->and(implode("\n", $ci->messages()))->toContain('Comparing against the merge base of main and HEAD');

        $repository->remove();
    });

    it('diffs a repository with no commits yet instead of failing', function (): void {
        $repository = TempRepository::create()->write('app/A.php', gateSwallowing('A'));
        $output = new RecordingRunnerOutput;

        $code = (new DiffRunner)->run(gateSloppy($repository->path), new DiffOptions(format: OutputFormat::Json), $output);

        expect($code)->toBe(ExitCode::FindingsAboveThreshold)
            ->and($output->reportBody())->toContain('"rule": "SL107"')
            ->and($output->messages())->toContain('notice: The repository has no commits yet; every file is compared as new.');

        $repository->remove();
    });
});
