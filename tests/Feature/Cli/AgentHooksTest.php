<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\HookCliCommand;
use Heyosseus\Sloppy\Cli\SloppyApplication;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Tests\Support\TempRepository;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

/**
 * An application whose `hook` command reads this payload instead of the real
 * standard input.
 */
function hookTester(string $payload): ApplicationTester
{
    $stdin = new SplTempFileObject;
    $stdin->fwrite($payload);
    $stdin->rewind();

    $application = new SloppyApplication('test');
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);
    $application->addCommand(new HookCliCommand($stdin));

    return new ApplicationTester($application);
}

it('installs the hooks from the command line', function (): void {
    $project = tempProject(['composer.json' => '{}', 'vendor/bin/sloppy' => '<?php']);
    $tester = hookTester('');

    $code = $tester->run(['command' => 'agents', 'action' => 'install', '--project' => $project], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::Success->value)
        ->and($tester->getDisplay())->toContain('Created .claude/settings.json')
        ->and((string) file_get_contents($project.'/.claude/settings.json'))->toContain('hook post-edit');

    removeTree($project);
});

it('previews the hooks without writing, and writes personal ones when asked', function (): void {
    $project = tempProject(['composer.json' => '{}']);
    $tester = hookTester('');

    $tester->run(['command' => 'agents', '--project' => $project, '--dry-run' => true], ['capture_stderr_separately' => true]);
    $preview = $tester->getDisplay();

    $tester->run(['command' => 'agents', '--project' => $project, '--local' => true], ['capture_stderr_separately' => true]);

    expect($preview)->toContain('hook stop')
        // No copy inside the project, so the hook points at the binary that ran.
        ->and($preview)->not->toContain('CLAUDE_PROJECT_DIR')
        ->and(is_file($project.'/.claude/settings.json'))->toBeFalse()
        ->and(is_file($project.'/.claude/settings.local.json'))->toBeTrue();

    removeTree($project);
});

it('rejects an action it does not have', function (): void {
    $project = tempProject(['composer.json' => '{}']);
    $tester = hookTester('');

    $code = $tester->run(['command' => 'agents', 'action' => 'remove', '--project' => $project], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::Error->value)
        ->and($tester->getDisplay())->toContain('Unknown action [remove]. The only action is install.');

    removeTree($project);
});

it('keeps the hook command out of the command list', function (): void {
    $tester = hookTester('');

    $tester->run(['command' => 'list']);

    expect($tester->getDisplay())->toContain('agents')
        ->and($tester->getDisplay())->not->toMatch('/^\s+hook\s/m');
});

it('fails open on anything it cannot make sense of', function (string $event, string $payload, string $notice): void {
    $tester = hookTester($payload);

    $code = $tester->run(['command' => 'hook', 'event' => $event], ['capture_stderr_separately' => true]);

    expect($code)->toBe(0)
        ->and($tester->getErrorOutput())->toBe('')
        ->and($tester->getDisplay())->toContain($notice);
})->with([
    'unknown event' => ['pre-commit', '{}', 'Unknown hook event [pre-commit]'],
    'broken payload' => ['stop', '{nope', 'not valid JSON'],
    'no project' => ['stop', '{"cwd": "/does/not/exist/anywhere"}', 'could not be resolved'],
]);

describe('against a real git repository', function (): void {
    beforeEach(function (): void {
        if (! TempRepository::gitIsAvailable()) {
            $this->markTestSkipped('git is not available on this machine.');
        }
    });

    it('hands a new finding back on standard error with exit 2', function (): void {
        $repository = TempRepository::create();
        $repository->write('composer.json', '{}')->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first');
        $repository->write('app/Bad.php', godMethodSource());

        $tester = hookTester(json_encode([
            'cwd' => $repository->path,
            'tool_input' => ['file_path' => $repository->path.'/app/Bad.php'],
        ], JSON_THROW_ON_ERROR));

        $code = $tester->run(['command' => 'hook', 'event' => 'post-edit'], ['capture_stderr_separately' => true]);

        $repository->remove();

        expect($code)->toBe(2)
            ->and($tester->getErrorOutput())->toContain('SL101 God Method')
            ->and($tester->getDisplay())->toBe('');
    });

    it('works end to end through the real binary, the way Claude Code runs it', function (): void {
        $repository = TempRepository::create();
        $repository->write('composer.json', '{}')->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first');
        $repository->write('app/Bad.php', godMethodSource());

        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/bin/sloppy', 'hook', 'stop'], $repository->path);
        $process->setInput(json_encode(['hook_event_name' => 'Stop', 'stop_hook_active' => false], JSON_THROW_ON_ERROR));
        $process->run();

        $repository->remove();

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toContain('before you finish')
            ->and($process->getErrorOutput())->toContain('SL101')
            ->and($process->getOutput())->toBe('');
    });
});
