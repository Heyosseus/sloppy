<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\DiffOptions;
use Heyosseus\Sloppy\Runner\DiffRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

it('says nothing and succeeds when sloppy is disabled', function (): void {
    $project = tempProject(['app/A.php' => '<?php class A {}']);
    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray(['enabled' => false], $project));

    $code = (new DiffRunner)->run($sloppy, new DiffOptions, $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->messages())->toBe(['info: Sloppy is disabled (sloppy.enabled is false).']);

    removeTree($project);
});

describe('against a real git repository', function (): void {
    beforeEach(function (): void {
        if (! TempRepository::gitIsAvailable()) {
            $this->markTestSkipped('git is not available on this machine.');
        }
    });

    it('errors when the directory is not a repository', function (): void {
        $project = tempProject(['app/A.php' => '<?php class A {}']);
        $output = new RecordingRunnerOutput;
        $sloppy = new Sloppy(Configuration::fromArray([], $project));

        $code = (new DiffRunner)->run($sloppy, new DiffOptions, $output);

        expect($code)->toBe(ExitCode::Error)
            ->and($output->messages())->toBe(['error: '.$project.' is not a git repository.']);

        removeTree($project);
    });

    it('errors when the revision cannot be resolved', function (): void {
        $repository = TempRepository::create();
        $repository->write('app/A.php', '<?php class A {}')->commit('first');

        $output = new RecordingRunnerOutput;
        $sloppy = new Sloppy(Configuration::fromArray([], $repository->path));

        $code = (new DiffRunner)->run($sloppy, new DiffOptions(base: 'nope'), $output);

        expect($code)->toBe(ExitCode::Error)
            ->and($output->messages())->toBe([
                'error: Revision [nope] could not be resolved in this repository.',
            ]);

        $repository->remove();
    });

    it('reports a change and fails on a new finding above the threshold', function (): void {
        $repository = TempRepository::create();
        $repository->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first');
        $repository->write('app/Bad.php', godMethodSource());

        $output = new RecordingRunnerOutput;
        $sloppy = new Sloppy(Configuration::fromArray(['fail_on' => 'medium'], $repository->path));

        $code = (new DiffRunner)->run($sloppy, new DiffOptions, $output);

        expect($code)->toBe(ExitCode::FindingsAboveThreshold)
            ->and($output->reportBody())->toContain('SL101');

        $repository->remove();
    });

    it('reports a pre-existing finding as inherited, not new', function (): void {
        $repository = TempRepository::create();
        $repository->write('app/Bad.php', godMethodSource())->commit('first');

        // Change the file without touching the god method, and without adding a
        // comment -- SL109 (narrative comment) would flag one and pollute the
        // "new" collection with a real, unrelated finding. A trailing blank
        // line is inert: git still sees the file as modified, but no rule has
        // anything to say about it. The file is analysed again, so the god
        // method finding is re-found -- and it must come back classified as
        // inherited. A runner that reported every finding in a changed file
        // would call it new and fail the build.
        $repository->write('app/Bad.php', godMethodSource()."\n");

        $output = new RecordingRunnerOutput;
        $sloppy = new Sloppy(Configuration::fromArray(['fail_on' => 'medium'], $repository->path));

        $code = (new DiffRunner)->run($sloppy, new DiffOptions(format: OutputFormat::Json), $output);

        $repository->remove();

        /** @var array{new: list<array<string, mixed>>, existing: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($output->reportBody(), true, 512, JSON_THROW_ON_ERROR);

        expect($code)->toBe(ExitCode::Success)
            ->and($decoded['new'])->toBeEmpty()
            ->and(array_column($decoded['existing'], 'rule'))->toContain('SL101');
    });

    it('fails the build for a finding the change introduced', function (): void {
        $repository = TempRepository::create();
        $repository->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first');
        $repository->write('app/Bad.php', godMethodSource());

        $output = new RecordingRunnerOutput;
        $sloppy = new Sloppy(Configuration::fromArray(['fail_on' => 'medium'], $repository->path));

        $code = (new DiffRunner)->run($sloppy, new DiffOptions(format: OutputFormat::Json), $output);

        $repository->remove();

        /** @var array{new: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($output->reportBody(), true, 512, JSON_THROW_ON_ERROR);

        expect($code)->toBe(ExitCode::FindingsAboveThreshold)
            ->and(array_column($decoded['new'], 'rule'))->toContain('SL101');
    });
});
