<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\ProfileException;
use Heyosseus\Sloppy\Architecture\ProfileProposal;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\ArchitectureOptions;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\ProposalWriter;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;

/**
 * @param  array<string, mixed>  $config
 * @return array{0: Sloppy, 1: string}
 */
function proposalProject(array $config = []): array
{
    $root = tempProject();

    return [new Sloppy(Configuration::fromArray(['paths' => ['app'], ...$config], $root)), $root];
}

/**
 * @param  array<string, int>  $unclear
 */
function laravelProposal(array $unclear = []): ProfileProposal
{
    return new ProfileProposal(['preset' => 'laravel'], ['Preset laravel: the closest match.'], $unclear);
}

function writeProposal(Sloppy $sloppy, ProfileProposal $proposal, RecordingRunnerOutput $output, bool $write = false, bool $force = false, OutputFormat $format = OutputFormat::Console): ExitCode
{
    return (new ProposalWriter)->write($sloppy, $proposal, new ArchitectureOptions(format: $format, write: $write, force: $force), $output, 'sloppy architecture init');
}

it('prints the proposal as php and writes nothing to a pipe without --write', function (): void {
    [$sloppy, $root] = proposalProject();
    $output = new RecordingRunnerOutput;

    expect(writeProposal($sloppy, laravelProposal(), $output))->toBe(ExitCode::Success)
        ->and($output->reports())->toHaveCount(1)
        ->and($output->reports()[0][0])->toBe('markdown')
        ->and($output->reportBody())->toBe(laravelProposal()->php('sloppy architecture init'))
        ->and($output->messages())->toBe(['notice: Nothing was written. Pass --write to save this as sloppy-architecture.php.'])
        ->and(is_file($root.'/sloppy-architecture.php'))->toBeFalse();

    removeTree($root);
});

it('writes the printed php with --write, and the file loads as the profile', function (): void {
    [$sloppy, $root] = proposalProject();
    $output = new RecordingRunnerOutput;

    expect(writeProposal($sloppy, laravelProposal(), $output, write: true))->toBe(ExitCode::Success)
        ->and(file_get_contents($root.'/sloppy-architecture.php'))->toBe($output->reportBody())
        ->and(require $root.'/sloppy-architecture.php')->toBe(['preset' => 'laravel'])
        ->and($output->messages())->toBe(['info: Wrote sloppy-architecture.php. Run `sloppy architecture` to check every class\'s role, then commit it.']);

    removeTree($root);
});

it('refuses to replace an existing file without --force, and replaces it with', function (): void {
    [$sloppy, $root] = proposalProject();
    file_put_contents($root.'/sloppy-architecture.php', '<?php return [];');
    $refused = new RecordingRunnerOutput;
    $forced = new RecordingRunnerOutput;

    expect(writeProposal($sloppy, laravelProposal(), $refused, write: true))->toBe(ExitCode::Error)
        ->and($refused->messages())->toBe(['error: sloppy-architecture.php already exists. Pass --force to replace it.'])
        ->and(file_get_contents($root.'/sloppy-architecture.php'))->toBe('<?php return [];')
        ->and(writeProposal($sloppy, laravelProposal(), $forced, write: true, force: true))->toBe(ExitCode::Success)
        ->and(file_get_contents($root.'/sloppy-architecture.php'))->toBe($forced->reportBody());

    removeTree($root);
});

it('refuses an existing file before asking a person anything about writing it', function (): void {
    [$sloppy, $root] = proposalProject();
    file_put_contents($root.'/sloppy-architecture.php', '<?php return [];');
    $output = new RecordingRunnerOutput(answers: [true], interactive: true);

    expect(writeProposal($sloppy, laravelProposal(), $output))->toBe(ExitCode::Error)
        ->and($output->messages())->toBe(['error: sloppy-architecture.php already exists. Pass --force to replace it.']);

    removeTree($root);
});

it('asks a person before writing, and writes nothing on a no', function (): void {
    [$sloppy, $root] = proposalProject();
    $declined = new RecordingRunnerOutput(answers: [false], interactive: true);

    expect(writeProposal($sloppy, laravelProposal(), $declined))->toBe(ExitCode::Success)
        ->and($declined->messages())->toBe(['confirm: Write this to sloppy-architecture.php?', 'info: Nothing was written.'])
        ->and(is_file($root.'/sloppy-architecture.php'))->toBeFalse();

    $accepted = new RecordingRunnerOutput(answers: [true], interactive: true);

    expect(writeProposal($sloppy, laravelProposal(), $accepted))->toBe(ExitCode::Success)
        ->and($accepted->messages())->toContain('confirm: Write this to sloppy-architecture.php?')
        ->and(is_file($root.'/sloppy-architecture.php'))->toBeTrue();

    removeTree($root);
});

it('does not ask before writing when --write already said yes', function (): void {
    [$sloppy, $root] = proposalProject();
    $output = new RecordingRunnerOutput(interactive: true);

    expect(writeProposal($sloppy, laravelProposal(), $output, write: true))->toBe(ExitCode::Success)
        ->and($output->messages())->not->toContain('confirm: Write this to sloppy-architecture.php?')
        ->and(is_file($root.'/sloppy-architecture.php'))->toBeTrue();

    removeTree($root);
});

it('never writes next to an architecture the configuration already declares', function (): void {
    [$sloppy, $root] = proposalProject(['architecture' => ['preset' => 'ddd']]);
    $output = new RecordingRunnerOutput;

    expect(writeProposal($sloppy, laravelProposal(), $output, write: true, force: true))->toBe(ExitCode::Success)
        ->and($output->messages())->toBe(['warn: sloppy.architecture already declares an architecture, so sloppy-architecture.php was not written. Merge the proposal into it, or remove it there and run this again.'])
        ->and(is_file($root.'/sloppy-architecture.php'))->toBeFalse();

    removeTree($root);
});

it('writes json for a machine, php included, and never touches the disk', function (): void {
    [$sloppy, $root] = proposalProject();
    $output = new RecordingRunnerOutput(interactive: true);

    expect(writeProposal($sloppy, laravelProposal(['App\Support' => 6]), $output, write: true, format: OutputFormat::Json))->toBe(ExitCode::Success);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($output->reportBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($output->reports()[0][0])->toBe('json')
        ->and($decoded['profile'])->toBe(['preset' => 'laravel'])
        ->and($decoded['notes'])->toBe(['Preset laravel: the closest match.'])
        ->and($decoded['unclear'])->toBe(['App\Support' => 6])
        ->and($decoded['php'])->toBe(laravelProposal()->php('sloppy architecture init'))
        ->and($output->messages())->toBe([])
        ->and(is_file($root.'/sloppy-architecture.php'))->toBeFalse();

    removeTree($root);
});

it('refuses a proposal that would not load, in either format', function (OutputFormat $format): void {
    [$sloppy, $root] = proposalProject();
    $broken = new ProfileProposal(['preset' => 'no-such-preset']);

    expect(fn (): ExitCode => writeProposal($sloppy, $broken, new RecordingRunnerOutput, write: true, format: $format))
        ->toThrow(ProfileException::class);
    expect(is_file($root.'/sloppy-architecture.php'))->toBeFalse();

    removeTree($root);
})->with([
    'console' => [OutputFormat::Console],
    'json' => [OutputFormat::Json],
]);

it('offers a role for each namespace it could not place, and adds the ones accepted', function (): void {
    [$sloppy, $root] = proposalProject();
    $output = new RecordingRunnerOutput(answers: [true, false, false], interactive: true);

    writeProposal($sloppy, laravelProposal(['App\Support' => 6, 'App\Reporting' => 4]), $output);

    expect($output->messages())->toBe([
        'confirm: App\Support holds 6 classes with no role. Give them one of their own, "support"?',
        'confirm: App\Reporting holds 4 classes with no role. Give them one of their own, "reporting"?',
        'confirm: Write this to sloppy-architecture.php?',
        'info: Nothing was written.',
    ])
        ->and($output->reportBody())->toContain("'support' => [")
        ->and($output->reportBody())->toContain("'namespace' => 'App\\Support\\*'")
        ->and($output->reportBody())->toContain('// - Role support: the classes in App\Support, as asked.')
        ->and($output->reportBody())->not->toContain("'reporting' => [");

    removeTree($root);
});

it('asks about at most five namespaces', function (): void {
    [$sloppy, $root] = proposalProject();
    $output = new RecordingRunnerOutput(interactive: true);
    $unclear = ['App\One' => 9, 'App\Two' => 8, 'App\Three' => 7, 'App\Four' => 6, 'App\Five' => 5, 'App\Six' => 4];

    writeProposal($sloppy, laravelProposal($unclear), $output);

    $questions = array_filter($output->messages(), static fn (string $message): bool => str_contains($message, 'classes with no role'));

    expect($questions)->toHaveCount(5)
        ->and(implode("\n", $questions))->not->toContain('App\Six');

    removeTree($root);
});

it('does not offer a role that already exists, or a name that is not a valid role', function (): void {
    [$sloppy, $root] = proposalProject();
    $output = new RecordingRunnerOutput(interactive: true);

    // `controller` is a laravel preset role; `2fa` cannot start a role name.
    writeProposal($sloppy, laravelProposal(['App\Controller' => 5, 'App\2fa' => 4]), $output);

    expect($output->messages())->toBe([
        'confirm: Write this to sloppy-architecture.php?',
        'info: Nothing was written.',
    ]);

    removeTree($root);
});

it('asks nothing about unplaced namespaces when nobody is there to answer', function (): void {
    [$sloppy, $root] = proposalProject();
    $output = new RecordingRunnerOutput(answers: [true]);

    writeProposal($sloppy, laravelProposal(['App\Support' => 6]), $output);

    expect($output->messages())->toBe(['notice: Nothing was written. Pass --write to save this as sloppy-architecture.php.'])
        ->and($output->reportBody())->not->toContain("'support' => [");

    removeTree($root);
});

it('says so when the file cannot be written', function (): void {
    [$sloppy, $root] = proposalProject();
    mkdir($root.'/sloppy-architecture.php');
    $output = new RecordingRunnerOutput;

    expect(writeProposal($sloppy, laravelProposal(), $output, write: true, force: true))->toBe(ExitCode::Error)
        ->and($output->messages())->toBe([sprintf('error: Could not write %s/sloppy-architecture.php.', $root)]);

    rmdir($root.'/sloppy-architecture.php');
    removeTree($root);
});
