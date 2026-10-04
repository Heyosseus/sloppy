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

    expect($policies['controller'])->toBe(['may_depend_on' => null, 'may_not_depend_on' => [], 'may_not' => ['env'], 'public_methods' => null, 'final' => false, 'advice' => 'Read config.', 'origin' => 'sloppy.php'])
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

/**
 * @param  list<string>  $words
 */
function architectureRun(Sloppy $sloppy, ?string $subject, array $words = [], ?string $format = null, RecordingRunnerOutput $output = new RecordingRunnerOutput, bool $write = false, bool $force = false, ?string $name = null): ExitCode
{
    return (new ArchitectureRunner)->run($sloppy, ArchitectureOptions::parse($subject, $words, $format, $name, $write, $force), $output);
}

it('draws the role graph in the format asked for', function (): void {
    [$sloppy, $root] = architectureProject(['architecture' => ['preset' => 'none', 'roles' => ['web' => ['suffix' => 'Controller'], 'domain' => ['namespace' => 'App\Domain\*']]]]);
    $mermaid = new RecordingRunnerOutput;
    $json = new RecordingRunnerOutput;

    expect(architectureRun($sloppy, 'graph', output: $mermaid))->toBe(ExitCode::Success)
        ->and($mermaid->reports()[0][0])->toBe('markdown')
        ->and($mermaid->reportBody())->toStartWith('flowchart LR')
        ->and(architectureRun($sloppy, 'graph', format: 'json', output: $json))->toBe(ExitCode::Success)
        ->and($json->reports()[0][0])->toBe('json')
        ->and(static fn (): ExitCode => architectureRun($sloppy, 'graph', format: 'svg'))->toThrow(InvalidArgumentException::class, 'writes mermaid, dot, json, not svg');

    removeTree($root);
});

it('says where a new class belongs', function (): void {
    [$sloppy, $root] = architectureProject();
    $text = new RecordingRunnerOutput;
    $json = new RecordingRunnerOutput;

    architectureRun($sloppy, 'place', ['a', 'service', 'for', 'orders'], output: $text, name: 'Refunds');
    architectureRun($sloppy, 'place', ['a service'], format: 'json', output: $json);

    /** @var array{role: array{name: string}} $decoded */
    $decoded = json_decode($json->reportBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($text->reportBody())->toStartWith('"a service for orders" belongs in the service role')
        ->and($text->reportBody())->toContain('- Class: App\Services\Refunds')
        ->and($decoded['role']['name'])->toBe('service')
        ->and(static fn (): ExitCode => architectureRun($sloppy, 'place'))->toThrow(InvalidArgumentException::class, 'Say what the class does');

    removeTree($root);
});

it('writes the prompt for an agent as markdown or json', function (): void {
    [$sloppy, $root] = architectureProject();
    $markdown = new RecordingRunnerOutput;
    $json = new RecordingRunnerOutput;

    architectureRun($sloppy, 'prompt', output: $markdown);
    architectureRun($sloppy, 'prompt', format: 'json', output: $json);

    expect($markdown->reports()[0][0])->toBe('markdown')
        ->and($markdown->reportBody())->toStartWith('# Describe this project\'s architecture for Sloppy')
        ->and(json_decode($json->reportBody(), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('draft');

    removeTree($root);
});

it('prints the inferred profile and writes it only when told to', function (): void {
    [$sloppy, $root] = architectureProject();
    $printed = new RecordingRunnerOutput;
    $written = new RecordingRunnerOutput;
    $refused = new RecordingRunnerOutput;
    $forced = new RecordingRunnerOutput;

    expect(architectureRun($sloppy, 'init', output: $printed))->toBe(ExitCode::Success)
        ->and($printed->reportBody())->toStartWith("<?php\n\n// Written by `sloppy architecture init`.")
        ->and($printed->messages())->toBe(['notice: Nothing was written. Pass --write to save this as sloppy-architecture.php.'])
        ->and(is_file($root.'/sloppy-architecture.php'))->toBeFalse()
        ->and(architectureRun($sloppy, 'init', output: $written, write: true))->toBe(ExitCode::Success)
        ->and($written->messages())->toBe(['info: Wrote sloppy-architecture.php. Run `sloppy architecture` to check every class\'s role, then commit it.'])
        ->and(is_array(require $root.'/sloppy-architecture.php'))->toBeTrue()
        ->and(architectureRun($sloppy, 'init', output: $refused, write: true))->toBe(ExitCode::Error)
        ->and($refused->messages())->toBe(['error: sloppy-architecture.php already exists. Pass --force to replace it.'])
        ->and(architectureRun($sloppy, 'init', output: $forced, write: true, force: true))->toBe(ExitCode::Success)
        ->and($sloppy->configuration->architecture()->source)->toBe('sloppy-architecture.php');

    removeTree($root);
});

it('asks about what it could not place, and before writing, when a person is there', function (): void {
    [$sloppy, $root] = architectureProject();

    foreach (['Str', 'Arr', 'Money', 'Retry', 'Uuid'] as $class) {
        file_put_contents($root.'/app/Support/'.$class.'.php', sprintf('<?php namespace App\Support; class %s {}', $class));
    }

    $declined = new RecordingRunnerOutput(answers: [false, false], interactive: true);
    $accepted = new RecordingRunnerOutput(answers: [true, true], interactive: true);

    architectureRun($sloppy, 'init', output: $declined);
    architectureRun($sloppy, 'init', output: $accepted);

    expect($declined->messages())->toBe([
        'confirm: App\Support holds 6 classes with no role. Give them one of their own, "support"?',
        'confirm: Write this to sloppy-architecture.php?',
        'info: Nothing was written.',
    ])
        ->and($accepted->reportBody())->toContain("'support' => [")
        ->and($accepted->reportBody())->toContain('// - Role support: the classes in App\Support, as asked.')
        ->and($sloppy->configuration->architecture()->role('support')?->origin)->toBe('sloppy-architecture.php');

    removeTree($root);
});

it('does not write next to an architecture sloppy.php already declares, and writes json for a machine', function (): void {
    [$sloppy, $root] = architectureProject(['architecture' => ['preset' => 'ddd']]);
    $declared = new RecordingRunnerOutput;
    $json = new RecordingRunnerOutput;

    architectureRun($sloppy, 'init', output: $declared, write: true);
    architectureRun($sloppy, 'init', format: 'json', output: $json, write: true);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json->reportBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($declared->messages())->toBe(['warn: sloppy.architecture already declares an architecture, so sloppy-architecture.php was not written. Merge the proposal into it, or remove it there and run this again.'])
        ->and(is_file($root.'/sloppy-architecture.php'))->toBeFalse()
        ->and(array_keys($decoded))->toBe(['profile', 'notes', 'unclear', 'php']);

    removeTree($root);
});

it('imports deptrac configuration, found or named', function (): void {
    [$sloppy, $root] = architectureProject();
    $missing = new RecordingRunnerOutput;
    $found = new RecordingRunnerOutput;
    $named = new RecordingRunnerOutput;
    $absent = new RecordingRunnerOutput;
    $broken = new RecordingRunnerOutput;

    $yaml = "deptrac:\n  layers:\n    - name: Controller\n      collectors:\n        - type: className\n          value: .*Controller\n";

    expect(architectureRun($sloppy, 'import', output: $missing))->toBe(ExitCode::Error)
        ->and($missing->messages()[0])->toStartWith('error: No deptrac configuration found.');

    file_put_contents($root.'/deptrac.yaml', $yaml);
    file_put_contents($root.'/other.yaml', $yaml);
    file_put_contents($root.'/broken.yaml', 'layers: [');

    expect(architectureRun($sloppy, 'import', output: $found))->toBe(ExitCode::Success)
        ->and($found->reportBody())->toContain("'controller' => [")
        ->and($found->reportBody())->toContain('// - Imported from deptrac.yaml.')
        ->and(architectureRun($sloppy, 'import', [$root.'/other.yaml'], output: $named))->toBe(ExitCode::Success)
        ->and(architectureRun($sloppy, 'import', ['nowhere.yaml'], output: $absent))->toBe(ExitCode::Error)
        ->and($absent->messages())->toBe(['error: nowhere.yaml does not exist.'])
        ->and(architectureRun($sloppy, 'import', ['broken.yaml'], output: $broken))->toBe(ExitCode::Error)
        ->and($broken->messages()[0])->toStartWith('error: broken.yaml is not valid YAML');

    removeTree($root);
});

it('reports a broken profile instead of describing it', function (): void {
    [$sloppy, $root] = architectureProject(['architecture' => ['preset' => 'symfony']]);
    $output = new RecordingRunnerOutput;

    expect(architectureRun($sloppy, null, output: $output))->toBe(ExitCode::Error)
        ->and($output->messages()[0])->toStartWith('error: sloppy.architecture.preset [symfony] is not a preset.');

    removeTree($root);
});

it('shows what the paths cover and where the profile came from', function (): void {
    [$sloppy, $root] = architectureProject();
    file_put_contents($root.'/sloppy-architecture.php', "<?php return ['covers' => 'app/*', 'policies' => ['controller' => ['final' => true, 'public_methods' => '__invoke']]];");
    $text = new RecordingRunnerOutput;
    $json = new RecordingRunnerOutput;

    architectureRun($sloppy, null, output: $text);
    architectureRun($sloppy, null, format: 'json', output: $json);

    /** @var array{source: string, covers: list<string>, roles: list<array{name: string, policy: array<string, mixed>|null}>} $decoded */
    $decoded = json_decode($json->reportBody(), true, flags: JSON_THROW_ON_ERROR);
    $policies = array_column($decoded['roles'], 'policy', 'name');

    expect($text->reportBody())->toContain('Architecture: preset laravel, from sloppy-architecture.php, 5 role(s)')
        ->and($text->reportBody())->toContain('Covers: app/*. A new class there that plays no role is reported by sloppy diff (SL307).')
        ->and($text->reportBody())->toContain('public methods: __invoke; final (sloppy-architecture.php)')
        ->and($decoded['source'])->toBe('sloppy-architecture.php')
        ->and($decoded['covers'])->toBe(['app/*'])
        ->and($policies['controller']['public_methods'] ?? null)->toBe(['__invoke'])
        ->and($policies['controller']['final'] ?? null)->toBeTrue();

    removeTree($root);
});

it('says so when the profile cannot be written', function (): void {
    [$sloppy, $root] = architectureProject();
    mkdir($root.'/sloppy-architecture.php');
    $output = new RecordingRunnerOutput;

    expect(architectureRun($sloppy, 'init', output: $output, write: true))->toBe(ExitCode::Error)
        ->and($output->messages())->toBe([sprintf('error: Could not write %s/sloppy-architecture.php.', $root)]);

    rmdir($root.'/sloppy-architecture.php');
    removeTree($root);
});
