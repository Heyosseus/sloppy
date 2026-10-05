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

it('uninstalls the hooks from the command line', function (): void {
    $project = tempProject(['composer.json' => '{}', 'vendor/bin/sloppy' => '<?php']);
    $tester = hookTester('');

    $tester->run(['command' => 'agents', 'action' => 'install', '--project' => $project], ['capture_stderr_separately' => true]);
    $code = $tester->run(['command' => 'agents', 'action' => 'uninstall', '--project' => $project], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::Success->value)
        ->and($tester->getDisplay())->toContain('Deleted .claude/settings.json')
        ->and(is_file($project.'/.claude/settings.json'))->toBeFalse()
        ->and(is_file($project.'/CLAUDE.md'))->toBeFalse();

    removeTree($project);
});

it('rejects an action it does not have', function (): void {
    $project = tempProject(['composer.json' => '{}']);
    $tester = hookTester('');

    $code = $tester->run(['command' => 'agents', 'action' => 'remove', '--project' => $project], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::Error->value)
        ->and($tester->getDisplay())->toContain('Unknown action [remove]. The actions are install and uninstall.');

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

    it('hands a new finding back as a JSON block decision, exiting 0', function (): void {
        // Not exit 2: through PowerShell -- what Claude Code uses on Windows
        // without Git Bash -- the exit code does not survive, and the block
        // became a warning. The JSON decision survives any shell.
        $repository = TempRepository::create();
        $repository->write('composer.json', '{}')->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first');
        $repository->write('app/Bad.php', godMethodSource());

        $tester = hookTester(json_encode([
            'cwd' => $repository->path,
            'tool_input' => ['file_path' => $repository->path.'/app/Bad.php'],
        ], JSON_THROW_ON_ERROR));

        $code = $tester->run(['command' => 'hook', 'event' => 'post-edit'], ['capture_stderr_separately' => true]);

        $repository->remove();

        $decision = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        expect($code)->toBe(0)
            ->and($decision['decision'])->toBe('block')
            ->and($decision['reason'])->toContain('SL101 God Method')
            ->and($tester->getErrorOutput())->toBe('');
    });

    it('works end to end through the real binary, the way Claude Code runs it', function (): void {
        $repository = TempRepository::create();
        $repository->write('composer.json', '{}')->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first');
        $repository->write('app/Bad.php', godMethodSource());

        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/bin/sloppy', 'hook', 'stop'], $repository->path);
        $process->setInput(json_encode(['hook_event_name' => 'Stop', 'stop_hook_active' => false], JSON_THROW_ON_ERROR));
        $process->run();

        $repository->remove();

        $decision = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($process->getExitCode())->toBe(0)
            ->and($decision['decision'])->toBe('block')
            ->and($decision['reason'])->toContain('before you finish')
            ->and($decision['reason'])->toContain('SL101');
    });

    it('reads a payload redirected from a file, not only a pipe', function (): void {
        // On Windows, Symfony's terminal probe used to swallow a redirected
        // file before the hook could read it, and the edit went unchecked.
        $repository = TempRepository::create();
        $repository->write('composer.json', '{}')->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first');
        $repository->write('app/Bad.php', godMethodSource());
        $repository->write('payload.json', json_encode([
            'cwd' => $repository->path,
            'tool_input' => ['file_path' => $repository->path.'/app/Bad.php'],
        ], JSON_THROW_ON_ERROR));

        $process = Process::fromShellCommandline(
            '"${:PHP}" "${:BINARY}" hook post-edit < payload.json',
            $repository->path,
            ['PHP' => PHP_BINARY, 'BINARY' => dirname(__DIR__, 3).'/bin/sloppy', 'COLUMNS' => false, 'LINES' => false],
        );
        $process->run();

        $repository->remove();

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toContain('"decision":"block"')
            ->and($process->getOutput())->toContain('SL101');
    });
});
