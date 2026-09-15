<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Integrations\HealthSnapshot;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\WatchOptions;
use Heyosseus\Sloppy\Runner\WatchRunner;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingDashboard;
use Heyosseus\Sloppy\Tests\Support\RecordingEditorLauncher;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;
use Heyosseus\Sloppy\Watch\EditorCommand;
use Heyosseus\Sloppy\Watch\KeyPress;

/**
 * @param  array<string, mixed>  $config
 */
function watched(string $root, array $config = []): Sloppy
{
    return new Sloppy(Configuration::fromArray([...['paths' => ['app'], 'exclude' => []], ...$config], $root));
}

/**
 * @param  list<KeyPress>  $script
 * @param  array<string, string>  $environment
 * @return array{0: ExitCode, 1: RecordingDashboard, 2: RecordingEditorLauncher, 3: RecordingRunnerOutput}
 */
function watch(
    Sloppy $sloppy,
    array $script = [],
    array $environment = [],
    ?callable $beforeRead = null,
    bool $keypresses = true,
): array {
    $dashboard = new RecordingDashboard($script, keypresses: $keypresses);
    $dashboard->beforeRead = $beforeRead === null ? null : $beforeRead(...);
    $launcher = new RecordingEditorLauncher;
    $output = new RecordingRunnerOutput;

    $exit = (new WatchRunner($dashboard, EditorCommand::from($environment), $launcher))
        ->run($sloppy, new WatchOptions, $output);

    return [$exit, $dashboard, $launcher, $output];
}

it('opens the terminal, draws a frame and gives the terminal back', function (): void {
    $root = tempProject(['app/A.php' => '<?php class A {}']);

    [$exit, $dashboard] = watch(watched($root), [KeyPress::Quit]);

    expect($exit)->toBe(ExitCode::Success)
        ->and($dashboard->opened)->toBe(1)
        ->and($dashboard->closed)->toBe(1)
        ->and($dashboard->frames)->not->toBeEmpty();

    removeTree($root);
});

it('gives the terminal back even when the loop blows up', function (): void {
    // A watch command that exits leaving the terminal in raw mode has broken
    // every shell command that runs after it.
    $root = tempProject(['app/A.php' => '<?php class A {}']);
    $dashboard = new RecordingDashboard([KeyPress::Quit]);
    $dashboard->beforeRead = static function (): void {
        throw new RuntimeException('The loop blew up.');
    };

    expect(fn (): ExitCode => (new WatchRunner($dashboard, EditorCommand::from([]), new RecordingEditorLauncher))
        ->run(watched($root), new WatchOptions, new RecordingRunnerOutput))
        ->toThrow(RuntimeException::class);

    expect($dashboard->closed)->toBe(1);

    removeTree($root);
});

it('reports exactly what a full scan reports', function (): void {
    // The whole point of re-parsing rather than re-reporting: a tick and
    // `sloppy scan` of the same tree must not be able to disagree.
    $root = tempProject(['app/Bad.php' => godMethodSource(), 'app/Good.php' => '<?php class Good {}']);
    $sloppy = watched($root);

    [, $dashboard] = watch($sloppy, [KeyPress::Quit]);

    $scan = HealthSnapshot::from($sloppy->analyze(), $sloppy->risks(), $sloppy->configuration->health()->top);

    expect($dashboard->lastFrame())->toContain(sprintf('%d/100', $scan->score))
        ->and($scan->findings)->toBeGreaterThan(0)
        ->and($dashboard->lastFrame())->toContain('SL101');

    removeTree($root);
});

it('picks up a file that changed and redraws with the new score', function (): void {
    $root = tempProject(['app/A.php' => '<?php class A {}']);

    // Written before the second read: the first poll banks the change, the
    // second sees the tree has settled, and only then is anything analysed.
    [, $dashboard] = watch(
        watched($root),
        [KeyPress::None, KeyPress::None, KeyPress::None, KeyPress::Quit],
        beforeRead: static function (int $frames) use ($root): void {
            if ($frames === 1) {
                file_put_contents($root.'/app/Bad.php', godMethodSource());
            }
        },
    );

    expect(implode("\n", $dashboard->frames[0]))->not->toContain('SL101')
        ->and($dashboard->lastFrame())->toContain('SL101')
        ->and($dashboard->lastFrame())->toContain('changed Bad.php');

    removeTree($root);
});

it('waits for the tree to settle before analysing a burst', function (): void {
    $root = tempProject(['app/A.php' => '<?php class A {}']);

    [, $dashboard] = watch(
        watched($root),
        [KeyPress::None, KeyPress::None, KeyPress::None, KeyPress::Quit],
        beforeRead: static function (int $frames) use ($root): void {
            file_put_contents($root.'/app/File'.$frames.'.php', '<?php class File'.$frames.' {}');
        },
    );

    // Files keep arriving for as long as the loop runs, so nothing ever
    // settles and no frame claims an analysis it did not do.
    expect($dashboard->lastFrame())->not->toContain('files changed');

    removeTree($root);
});

it('rescans on r, catching an edit the stamps could not see', function (): void {
    $root = tempProject(['app/A.php' => '<?php class Aaa {}']);
    $path = $root.'/app/A.php';
    $when = (int) filemtime($path);

    [, $dashboard] = watch(
        watched($root),
        [KeyPress::Rescan, KeyPress::Quit],
        beforeRead: static function (int $frames) use ($path, $when): void {
            if ($frames === 1) {
                // Same length, same modification time: invisible to polling,
                // which is exactly what the rescan key is the answer to.
                file_put_contents($path, '<?php class Bbb {}');
                touch($path, $when);
            }
        },
    );

    expect($dashboard->frames)->toHaveCount(2)
        ->and($dashboard->lastFrame())->toContain('1 files');

    removeTree($root);
});

it('opens the selected finding in the editor', function (): void {
    $root = tempProject(['app/Bad.php' => godMethodSource()]);

    [, $dashboard, $launcher] = watch(
        watched($root),
        [KeyPress::Open, KeyPress::Quit],
        ['EDITOR' => 'code'],
    );

    expect($launcher->launched)->toHaveCount(1)
        ->and($launcher->launched[0][0])->toBe('code')
        ->and($launcher->launched[0][2])->toContain('app/Bad.php:')
        // The terminal is handed over while the editor has it, and taken back
        // afterwards, or the editor and the dashboard fight over the screen.
        ->and($dashboard->closed)->toBe(2)
        ->and($dashboard->opened)->toBe(2);

    removeTree($root);
});

it('moves the selection before opening', function (): void {
    $root = tempProject(['app/Bad.php' => godMethodSource(), 'app/Worse.php' => godMethodSource()]);

    [, , $launcher] = watch(
        watched($root),
        [KeyPress::Down, KeyPress::Open, KeyPress::Quit],
        ['EDITOR' => 'vim'],
    );

    $first = HealthSnapshot::from(watched($root)->analyze(), watched($root)->risks(), 5)->top[1];

    expect($launcher->launched[0])->toBe(['vim', '+'.$first['line'], str_replace('\\', '/', $root).'/'.$first['file']]);

    removeTree($root);
});

it('does nothing on enter when no editor is configured', function (): void {
    $root = tempProject(['app/Bad.php' => godMethodSource()]);

    [, $dashboard, $launcher] = watch(watched($root), [KeyPress::Open, KeyPress::Quit]);

    expect($launcher->launched)->toBe([])
        ->and($dashboard->closed)->toBe(1);

    removeTree($root);
});

it('does nothing on enter when there is nothing flagged', function (): void {
    $root = tempProject(['app/Good.php' => '<?php class Good {}']);

    [, , $launcher] = watch(watched($root), [KeyPress::Open, KeyPress::Quit], ['EDITOR' => 'code']);

    expect($launcher->launched)->toBe([]);

    removeTree($root);
});

it('tells the frame when the terminal cannot offer keypresses', function (): void {
    $root = tempProject(['app/A.php' => '<?php class A {}']);

    [, $dashboard] = watch(watched($root), [KeyPress::Quit], keypresses: false);

    expect($dashboard->lastFrame())->toContain('Ctrl+C');

    removeTree($root);
});

it('says so and stops when Sloppy is turned off', function (): void {
    $root = tempProject(['app/A.php' => '<?php class A {}']);

    [$exit, $dashboard, , $output] = watch(watched($root, ['enabled' => false]), [KeyPress::Quit]);

    expect($exit)->toBe(ExitCode::Success)
        ->and($dashboard->opened)->toBe(0)
        ->and($output->messages())->toContain('info: Sloppy is disabled (sloppy.enabled is false).');

    removeTree($root);
});

it('reports a bad option without taking over the terminal', function (): void {
    $root = tempProject(['app/A.php' => '<?php class A {}']);
    $dashboard = new RecordingDashboard([KeyPress::Quit]);
    $output = new RecordingRunnerOutput;

    $exit = (new WatchRunner($dashboard, EditorCommand::from([]), new RecordingEditorLauncher))
        ->run(watched($root), new WatchOptions(minConfidence: 900), $output);

    expect($exit)->toBe(ExitCode::Error)
        ->and($dashboard->opened)->toBe(0);

    removeTree($root);
});

it('refuses to run when there is no terminal to draw on', function (): void {
    // Piped, redirected, or in CI there is no way to press q and nothing to
    // look at, so a loop that never ends is the only possible outcome.
    $root = tempProject(['app/A.php' => '<?php class A {}']);
    $dashboard = new RecordingDashboard([KeyPress::Quit], terminal: false);
    $output = new RecordingRunnerOutput;

    $exit = (new WatchRunner($dashboard, EditorCommand::from([]), new RecordingEditorLauncher))
        ->run(watched($root), new WatchOptions, $output);

    expect($exit)->toBe(ExitCode::Error)
        ->and($dashboard->opened)->toBe(0)
        ->and($dashboard->frames)->toBe([])
        ->and(implode("\n", $output->messages()))->toContain('terminal');

    removeTree($root);
});
