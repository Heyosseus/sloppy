<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Agent\HookEvent;
use Heyosseus\Sloppy\Agent\HookPayload;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Runner\HookRunner;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

/**
 * What Claude Code sends after it edits a file, for a path in the repository.
 */
function editPayload(TempRepository $repository, string $relativePath): HookPayload
{
    return HookPayload::fromJson(json_encode([
        'hook_event_name' => 'PostToolUse',
        'tool_name' => 'Edit',
        'cwd' => $repository->path,
        'tool_input' => ['file_path' => $repository->path.'/'.$relativePath],
    ], JSON_THROW_ON_ERROR));
}

/**
 * What Claude Code sends when the agent tries to finish.
 */
function stopPayload(bool $active = false): HookPayload
{
    return HookPayload::fromJson(json_encode([
        'hook_event_name' => 'Stop',
        'stop_hook_active' => $active,
    ], JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $config
 */
function hookSloppy(string $path, array $config = []): Sloppy
{
    return new Sloppy(Configuration::fromArray($config, $path));
}

it('says nothing when sloppy is disabled', function (): void {
    $project = tempProject(['app/A.php' => '<?php class A {}']);

    $outcome = (new HookRunner)->run(hookSloppy($project, ['enabled' => false]), HookEvent::Stop, stopPayload());

    expect($outcome->blocks())->toBeFalse()
        ->and($outcome->exitCode())->toBe(0)
        ->and($outcome->stderr)->toBe('');

    removeTree($project);
});

it('ignores an edit to a file that is not PHP, without touching git', function (): void {
    $project = tempProject(['README.md' => '# hi']);
    $payload = HookPayload::fromJson(json_encode(['tool_input' => ['file_path' => $project.'/README.md']], JSON_THROW_ON_ERROR));

    $outcome = (new HookRunner)->run(hookSloppy($project), HookEvent::PostEdit, $payload);

    expect($outcome->blocks())->toBeFalse()
        ->and($outcome->stdout)->toBe('');

    removeTree($project);
});

it('ignores an edit with no file path, or one outside the project', function (): void {
    $project = tempProject(['app/A.php' => '<?php class A {}']);
    $runner = new HookRunner;

    $none = $runner->run(hookSloppy($project), HookEvent::PostEdit, HookPayload::fromJson('{}'));
    $outside = $runner->run(hookSloppy($project), HookEvent::PostEdit, HookPayload::fromJson(
        json_encode(['tool_input' => ['file_path' => '/somewhere/else/B.php']], JSON_THROW_ON_ERROR),
    ));

    expect($none->blocks())->toBeFalse()
        ->and($outside->blocks())->toBeFalse();

    removeTree($project);
});

it('fails open, with a notice, where diff mode cannot run', function (): void {
    $project = tempProject(['app/A.php' => '<?php class A {}']);

    $outcome = (new HookRunner)->run(hookSloppy($project), HookEvent::Stop, stopPayload());

    expect($outcome->blocks())->toBeFalse()
        ->and($outcome->stdout)->toContain('not a git repository');

    removeTree($project);
});

describe('against a real git repository', function (): void {
    beforeEach(function (): void {
        if (! TempRepository::gitIsAvailable()) {
            $this->markTestSkipped('git is not available on this machine.');
        }
    });

    it('fails open in a repository with no commits yet', function (): void {
        $repository = TempRepository::create();
        $repository->write('app/Bad.php', godMethodSource());

        $outcome = (new HookRunner)->run(hookSloppy($repository->path), HookEvent::PostEdit, editPayload($repository, 'app/Bad.php'));

        $repository->remove();

        expect($outcome->blocks())->toBeFalse()
            ->and($outcome->stdout)->toContain('HEAD');
    });

    it('feeds back a finding the edit introduced', function (): void {
        $repository = TempRepository::create();
        $repository->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first');
        $repository->write('app/Bad.php', godMethodSource());

        $outcome = (new HookRunner)->run(hookSloppy($repository->path), HookEvent::PostEdit, editPayload($repository, 'app/Bad.php'));

        $repository->remove();

        expect($outcome->blocks())->toBeTrue()
            ->and($outcome->exitCode())->toBe(2)
            ->and($outcome->stderr)->toContain('app/Bad.php:5 SL101 God Method (high)')
            ->and($outcome->stderr)->toContain('this edit to app/Bad.php introduced 1 finding');
    });

    it('stays quiet about a finding the file already had', function (): void {
        $repository = TempRepository::create();
        $repository->write('app/Bad.php', godMethodSource())->commit('first');
        $repository->write('app/Bad.php', godMethodSource()."\n");

        $outcome = (new HookRunner)->run(hookSloppy($repository->path), HookEvent::PostEdit, editPayload($repository, 'app/Bad.php'));

        $repository->remove();

        expect($outcome->blocks())->toBeFalse()
            ->and($outcome->stderr)->toBe('');
    });

    it('only reports on the file that was edited', function (): void {
        $repository = TempRepository::create();
        $repository->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first');
        $repository->write('app/Bad.php', godMethodSource());
        $repository->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n\n");

        $outcome = (new HookRunner)->run(hookSloppy($repository->path), HookEvent::PostEdit, editPayload($repository, 'app/Fine.php'));

        $repository->remove();

        expect($outcome->blocks())->toBeFalse();
    });

    it('stops the agent finishing with a new finding at the threshold', function (): void {
        $repository = TempRepository::create();
        $repository->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first');
        $repository->write('app/Bad.php', godMethodSource());

        $outcome = (new HookRunner)->run(hookSloppy($repository->path, ['fail_on' => 'high']), HookEvent::Stop, stopPayload());

        $repository->remove();

        expect($outcome->blocks())->toBeTrue()
            ->and($outcome->stderr)->toContain('before you finish')
            ->and($outcome->stderr)->toContain('at or above high')
            ->and($outcome->stderr)->toContain('SL101');
    });

    it('lets the agent finish the second time, and says what is left', function (): void {
        $repository = TempRepository::create();
        $repository->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first');
        $repository->write('app/Bad.php', godMethodSource());

        $outcome = (new HookRunner)->run(hookSloppy($repository->path), HookEvent::Stop, stopPayload(active: true));

        $repository->remove();

        expect($outcome->blocks())->toBeFalse()
            ->and($outcome->stderr)->toBe('')
            ->and($outcome->stdout)->toContain('SL101');
    });

    it('lets the agent finish when the new findings are below the threshold', function (string $failOn): void {
        $repository = TempRepository::create();
        $repository->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first');
        $repository->write('app/Bad.php', godMethodSource());

        $outcome = (new HookRunner)->run(hookSloppy($repository->path, ['fail_on' => $failOn]), HookEvent::Stop, stopPayload());

        $repository->remove();

        expect($outcome->blocks())->toBeFalse();
    })->with(['critical', 'never']);

    it('lets the agent finish a clean change', function (): void {
        $repository = TempRepository::create();
        $repository->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first');

        $outcome = (new HookRunner)->run(hookSloppy($repository->path), HookEvent::Stop, stopPayload());

        $repository->remove();

        expect($outcome->blocks())->toBeFalse()
            ->and($outcome->stdout)->toBe('');
    });
});
