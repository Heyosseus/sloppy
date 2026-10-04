<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\ArchitectureOptions;
use Heyosseus\Sloppy\Runner\ArchitectureRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;

/**
 * @param  array<string, mixed>  $config
 * @return array{0: Sloppy, 1: string}
 */
function architectureProject(array $config = []): array
{
    $root = tempProject([
        'app/Http/Controllers/OrderController.php' => '<?php namespace App\Http\Controllers; class OrderController {}',
        'app/Models/Order.php' => '<?php namespace App\Models; class Order extends \Illuminate\Database\Eloquent\Model {}',
        'app/Services/OrderService.php' => '<?php namespace App\Services; class OrderService {}',
        'app/Domain/Billing/Invoice.php' => '<?php namespace App\Domain\Billing; class Invoice extends \Illuminate\Database\Eloquent\Model {}',
        'app/Support/Clock.php' => '<?php namespace App\Support; class Clock {}',
        'app/Reports/Clock.php' => '<?php namespace App\Reports; class Clock {}',
        'app/Broken.php' => '<?php class {',
    ]);

    return [new Sloppy(Configuration::fromArray(['paths' => ['app'], ...$config], $root)), $root];
}

it('shows how many classes play each role, with examples, in the order roles are tried', function (): void {
    [$sloppy, $root] = architectureProject();
    $output = new RecordingRunnerOutput;

    $code = (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions, $output);
    $report = $output->reportBody();

    expect($code)->toBe(ExitCode::Success)
        ->and($report)->toContain('Architecture: preset laravel, 5 role(s), 6 declaration(s).')
        ->and($report)->toMatch('/model\s+2\s+An Eloquent model/')
        ->and($report)->toContain('e.g. App\Domain\Billing\Invoice, App\Models\Order')
        ->and($report)->toMatch('/controller\s+1\s+/')
        ->and($report)->toMatch('/form-request\s+0\s+/')
        ->and($report)->toMatch('/unclassified\s+2\s+No role/')
        ->and(strpos($report, 'form-request'))->toBeLessThan(strpos($report, 'service'))
        ->and($output->messages())->toBe([]);

    removeTree($root);
});

it('shortens a long list of examples', function (): void {
    [$sloppy, $root] = architectureProject(['architecture' => ['preset' => 'none', 'roles' => ['anything' => ['kind' => 'class']]]]);
    $output = new RecordingRunnerOutput;

    (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions, $output);

    expect($output->reportBody())->toContain('and 3 more')
        ->and($output->reportBody())->toContain('kind class');

    removeTree($root);
});

it('warns about a project role that matches nothing, but not about an unused preset role', function (): void {
    [$sloppy, $root] = architectureProject(['architecture' => ['roles' => ['action' => ['suffix' => 'Action']]]]);
    $output = new RecordingRunnerOutput;

    (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions, $output);

    expect($output->messages())->toBe([
        'warn: Role [action] matches no class in the analysed paths. Check its matchers: suffix Action.',
    ]);

    removeTree($root);
});

it('writes the overview as json', function (): void {
    [$sloppy, $root] = architectureProject();
    $output = new RecordingRunnerOutput;

    (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions(format: OutputFormat::Json), $output);

    /** @var array{schema: int, preset: string, roles: list<array{name: string, origin: string, classes: list<string>}>, unclassified: list<string>} $decoded */
    $decoded = json_decode($output->reportBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($output->reports()[0][0])->toBe('json')
        ->and($decoded['preset'])->toBe('laravel')
        ->and($decoded['roles'][1]['name'])->toBe('model')
        ->and($decoded['roles'][1]['origin'])->toBe('preset laravel')
        ->and($decoded['roles'][1]['classes'])->toBe(['App\Domain\Billing\Invoice', 'App\Models\Order'])
        ->and($decoded['unclassified'])->toBe(['App\Reports\Clock', 'App\Support\Clock']);

    removeTree($root);
});

it('explains one class: its role, why, and the roles it lost', function (): void {
    [$sloppy, $root] = architectureProject();
    $output = new RecordingRunnerOutput;

    $code = (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions(class: '\App\Domain\Billing\Invoice'), $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->reportBody())->toContain('App\Domain\Billing\Invoice (app/Domain/Billing/Invoice.php:1)')
        ->and($output->reportBody())->toContain('Role:     model, from preset laravel')
        ->and($output->reportBody())->toContain('Matched:  kind class, and parent')
        ->and($output->reportBody())->toContain('Also matched service (preset laravel), which is tried later.');

    removeTree($root);
});

it('finds a class by its short name when only one has it', function (): void {
    [$sloppy, $root] = architectureProject();
    $output = new RecordingRunnerOutput;

    (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions(class: 'OrderController'), $output);

    expect($output->reportBody())->toContain('Role:     controller, from preset laravel');

    removeTree($root);
});

it('says plainly when a class has no role', function (): void {
    [$sloppy, $root] = architectureProject();
    $output = new RecordingRunnerOutput;

    (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions(class: 'App\Support\Clock'), $output);

    expect($output->reportBody())->toContain('Role:  none')
        ->and($output->reportBody())->toContain('no rule about where code belongs reports this class');

    removeTree($root);
});

it('explains a class as json', function (): void {
    [$sloppy, $root] = architectureProject();
    $output = new RecordingRunnerOutput;

    (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions(class: 'App\Domain\Billing\Invoice', format: OutputFormat::Json), $output);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($output->reportBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toMatchArray([
        'class' => 'App\Domain\Billing\Invoice',
        'file' => 'app/Domain/Billing/Invoice.php',
        'role' => 'model',
        'origin' => 'preset laravel',
        'also_matched' => ['service'],
    ]);

    removeTree($root);
});

it('refuses a class it cannot find, or cannot tell apart', function (string $name, string $message): void {
    [$sloppy, $root] = architectureProject();
    $output = new RecordingRunnerOutput;

    $code = (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions(class: $name), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages())->toBe(['error: '.$message]);

    removeTree($root);
})->with([
    'missing' => ['Nowhere', 'No class named Nowhere in the analysed paths.'],
    'ambiguous' => ['Clock', 'Clock is ambiguous. Name one of: App\Reports\Clock, App\Support\Clock.'],
]);

it('writes only console or json', function (): void {
    expect(static fn (): ArchitectureOptions => new ArchitectureOptions(format: OutputFormat::Sarif))
        ->toThrow(InvalidArgumentException::class, 'sloppy architecture writes console or json, not sarif.');
});

it('lists the policies and boundaries it enforces, and where each came from', function (): void {
    [$sloppy, $root] = architectureProject(['architecture' => [
        'policies' => ['controller' => ['may_depend_on' => ['service'], 'may_not_depend_on' => ['model'], 'may_not' => ['db']]],
        'boundaries' => ['modules' => 'App\Modules\{module}\*'],
    ]]);
    $output = new RecordingRunnerOutput;

    (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions, $output);

    expect($output->reportBody())->toContain('Policies:')
        ->and($output->reportBody())->toMatch('/controller\s+may depend only on: service; may not depend on: model; may not: db\.read, db\.write \(sloppy\.php\)/')
        ->and($output->reportBody())->toContain('Boundaries: modules App\Modules\{module}\*; public nothing; shared nothing (sloppy.php)');

    removeTree($root);
});

it('writes policies and boundaries into the json', function (): void {
    [$sloppy, $root] = architectureProject(['architecture' => ['preset' => 'modular', 'policies' => ['controller' => ['may_not' => ['env'], 'advice' => 'Read config.']]]]);
    $output = new RecordingRunnerOutput;

    (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions(format: OutputFormat::Json), $output);

    /** @var array{roles: list<array{name: string, policy: array<string, mixed>|null}>, boundaries: array<string, mixed>} $decoded */
    $decoded = json_decode($output->reportBody(), true, flags: JSON_THROW_ON_ERROR);
    $policies = array_column($decoded['roles'], 'policy', 'name');

    expect($policies['controller'])->toBe(['may_depend_on' => null, 'may_not_depend_on' => [], 'may_not' => ['env'], 'advice' => 'Read config.', 'origin' => 'sloppy.php'])
        ->and($policies['model'])->toBeNull()
        ->and($decoded['boundaries'])->toBe([
            'modules' => ['Modules\{module}\*', 'App\Modules\{module}\*'],
            'public' => ['Contracts\*', 'Events\*', 'Data\*', 'Enums\*'],
            'shared' => ['Modules\Shared\*', 'App\Modules\Shared\*'],
            'origin' => 'preset modular',
        ]);

    removeTree($root);
});

it('names the policy a class is held to when it explains it', function (): void {
    [$sloppy, $root] = architectureProject(['architecture' => ['policies' => ['controller' => ['may_not' => ['db']]]]]);
    $text = new RecordingRunnerOutput;
    $json = new RecordingRunnerOutput;

    (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions(class: 'OrderController'), $text);
    (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions(class: 'OrderService', format: OutputFormat::Json), $json);

    /** @var array{policy: mixed} $decoded */
    $decoded = json_decode($json->reportBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($text->reportBody())->toContain('Policy:   may not: db.read, db.write (sloppy.php)')
        ->and($decoded['policy'])->toBeNull();

    removeTree($root);
});
