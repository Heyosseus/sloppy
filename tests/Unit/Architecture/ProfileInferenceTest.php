<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\ProfileInference;
use Heyosseus\Sloppy\Architecture\ProfilePrompt;
use Heyosseus\Sloppy\Architecture\ProfileProposal;
use Heyosseus\Sloppy\Configuration\ComposerJson;

/**
 * @param  array<string, string>  $files
 * @param  list<string>  $packages
 */
function inferenceOf(array $files, array $packages = []): ProfileInference
{
    $root = tempProject(['composer.json' => json_encode(['require' => array_fill_keys($packages, '*')], JSON_THROW_ON_ERROR)]);
    $inference = new ProfileInference(architectureSnapshotOf($files)->index, new ComposerJson($root), ['app']);
    removeTree($root);

    return $inference;
}

/**
 * @return array<string, string>
 */
function classes(string $namespace, string ...$names): array
{
    $files = [];

    foreach ($names as $name) {
        $files[str_replace('\\', '/', $namespace).'/'.$name.'.php'] = sprintf('<?php namespace %s; class %s {}', $namespace, $name);
    }

    return $files;
}

it('picks the preset the code follows', function (array $files, array $packages, string $preset, string $reason): void {
    $proposal = inferenceOf($files, $packages)->propose();

    expect($proposal->profile['preset'])->toBe($preset)
        ->and($proposal->notes[0])->toBe($reason);
})->with([
    'modules by package' => [[], ['nwidart/laravel-modules'], 'modular', 'Preset modular: nwidart/laravel-modules is installed.'],
    'modules by namespace' => [[...classes('Modules\Billing', 'Invoice'), ...classes('App\Modules\Shipping', 'Rate')], [], 'modular', 'Preset modular: 2 modules (Billing, Shipping).'],
    'ports' => [classes('App\Domain\Ports', 'Rates'), [], 'hexagonal', 'Preset hexagonal: the code has a domain with ports and adapters.'],
    'adapters beside a domain' => [[...classes('App\Domain', 'Order'), ...classes('App\Adapters', 'Db')], [], 'hexagonal', 'Preset hexagonal: the code has a domain with ports and adapters.'],
    'adapters with no domain' => [classes('App\Services\Streamer\Adapters', 'S3'), [], 'laravel', 'Preset laravel: no layering beyond Laravel\'s own was found.'],
    'domain and infrastructure' => [[...classes('App\Domain', 'Order'), ...classes('App\Infrastructure', 'Db')], [], 'ddd', 'Preset ddd: the code has Domain, Application and Infrastructure layers.'],
    'actions by package' => [[], ['lorisleiva/laravel-actions'], 'laravel-actions', 'Preset laravel-actions: the use cases live in action classes.'],
    'actions by namespace' => [classes('App\Actions', 'Refund', 'Cancel', 'Ship'), [], 'laravel-actions', 'Preset laravel-actions: the use cases live in action classes.'],
    'repositories by name' => [classes('App\Data', 'OrderRepository', 'UserRepository', 'LineRepository'), [], 'service-repository', 'Preset service-repository: queries live in repositories.'],
    'repositories by namespace' => [classes('App\Repositories', 'Orders', 'Users'), [], 'service-repository', 'Preset service-repository: queries live in repositories.'],
    'nothing in particular' => [classes('App', 'Kernel'), [], 'laravel', 'Preset laravel: no layering beyond Laravel\'s own was found.'],
]);

it('gives a role to each family of classes the preset leaves out, and covers the paths once nearly everything has one', function (): void {
    $proposal = inferenceOf([
        ...classes('App\Data', 'OrderData', 'UserData', 'InvoiceData'),
        ...classes('App\Http\Controllers', 'OrderController'),
        ...classes('App\Enums', 'Status'),
    ])->propose();

    expect($proposal->profile)->toBe([
        'preset' => 'laravel',
        'roles' => ['data' => ['description' => 'Classes named *Data (3 when this was written).', 'suffix' => 'Data']],
    ])
        ->and($proposal->notes)->toBe([
            'Preset laravel: no layering beyond Laravel\'s own was found.',
            'Role data: Classes named *Data (3 when this was written).',
            '1 of 5 classes would play no role.',
        ])
        ->and($proposal->unclear)->toBe([]);

    expect(inferenceOf(classes('App\Data', 'OrderData', 'UserData', 'InvoiceData'))->propose()->profile['covers'] ?? null)->toBe(['app/*']);
});

it('gives a role only to words that say what a class is, or to a family with a namespace of its own', function (): void {
    $proposal = inferenceOf([
        ...classes('App\Models\Music', 'RockAlbum', 'JazzAlbum', 'PopAlbum'),
        ...classes('App\Presenters', 'UserPresenter', 'OrderPresenter', 'LinePresenter'),
        ...classes('App\Reports', 'SalesReport', 'StockReport', 'RevenueReport'),
        ...classes('App\Shelf', 'TopShelf', 'LowShelf', 'MidShelf'),
        ...classes('App\Other', 'HighShelf'),
        ...classes('App\Mixed', 'OneLabel', 'TwoLabel'),
        ...classes('App\Labels', 'ThreeLabel'),
        ...classes('App', 'Kernel'),
    ])->propose();

    expect(array_keys($proposal->profile['roles'] ?? []))->toBe(['presenter', 'report', 'shelf']);
});

it('leaves a family alone when its suffix also names classes that play a role', function (): void {
    $proposal = inferenceOf([
        ...classes('App\Data', 'OrderHandler', 'UserHandler', 'InvoiceHandler'),
        ...classes('App\Services', 'PaymentHandler'),
    ])->propose();

    expect($proposal->profile)->not->toHaveKey('roles');
});

it('names the namespaces it could not decide about', function (): void {
    $proposal = inferenceOf(classes('App\Support', 'Str', 'Arr', 'Clock', 'Money', 'Retry', 'Uuid'))->propose();

    expect($proposal->unclear)->toBe(['App\Support' => 6])
        ->and($proposal->profile)->toBe(['preset' => 'laravel']);
});

it('reports what the code shows', function (): void {
    $facts = inferenceOf([...classes('App\Domain', 'Order', 'OrderData', 'UserData', 'LineData')], ['lorisleiva/laravel-actions'])->facts();

    expect($facts)->toBe([
        'classes' => 4,
        'packages' => ['lorisleiva/laravel-actions'],
        'namespaces' => ['Domain' => 4],
        'modules' => [],
        'suffixes' => ['Data' => 3],
    ]);
});

it('turns names into role names', function (string $words, string $role): void {
    expect(ProfileInference::roleName($words))->toBe($role);
})->with([
    ['ServiceProvider', 'service-provider'],
    ['Domain Model', 'domain-model'],
    ['HTTP', 'http'],
    ['  Data  ', 'data'],
]);

it('writes a proposal as PHP that loads back to the same array', function (): void {
    $proposal = new ProfileProposal([
        'preset' => 'none',
        'roles' => ['quote\'s' => ['namespace' => ['App\Quotes\*', 'Trailing\\'], 'intended_abstraction' => true, 'weight' => 2, 'nothing' => null, 'empty' => []]],
    ], ['A note.']);

    $root = tempProject(['profile.php' => $proposal->php('sloppy architecture init')]);

    expect(require $root.'/profile.php')->toBe($proposal->profile)
        ->and($proposal->php('sloppy architecture init'))->toContain("'App\\Quotes\\*'")
        ->and($proposal->php('sloppy architecture init'))->toContain("// - A note.\n")
        ->and($proposal->php('sloppy architecture init'))->toStartWith("<?php\n\n// Written by `sloppy architecture init`.")
        ->and($proposal->toArray())->toBe(['profile' => $proposal->profile, 'notes' => ['A note.'], 'unclear' => []]);

    removeTree($root);
});

it('adds a role ahead of the rest, and validates what it proposes', function (): void {
    $proposal = (new ProfileProposal(['preset' => 'laravel', 'roles' => ['data' => ['suffix' => 'Data']]], [], ['App\Support' => 6]))
        ->withRole('support', ['namespace' => 'App\Support\*'], 'Asked.');

    expect(array_keys($proposal->profile['roles']))->toBe(['support', 'data'])
        ->and($proposal->notes)->toBe(['Asked.'])
        ->and($proposal->unclear)->toBe(['App\Support' => 6])
        ->and($proposal->validate()->roles[0]->origin)->toBe('sloppy-architecture.php')
        ->and((new ProfileProposal(['preset' => 'laravel']))->withRole('a', ['suffix' => 'A'], 'x')->profile['roles'])->toBe(['a' => ['suffix' => 'A']]);
});

it('writes a prompt with the format, the facts and the draft', function (): void {
    $prompt = new ProfilePrompt(inferenceOf([...classes('App\Support', 'Str', 'Arr', 'Clock', 'Money', 'Retry'), ...classes('App\Data', 'AData', 'BData', 'CData')]));
    $markdown = $prompt->markdown();
    $data = $prompt->toArray();

    expect($markdown)->toStartWith("# Describe this project's architecture for Sloppy\n")
        ->and($markdown)->toContain('Write `sloppy-architecture.php` in the project root')
        ->and($markdown)->toContain('- Start from the closest preset')
        ->and($markdown)->toContain('"public_methods"')
        ->and($markdown)->toContain('- 8 classes.')
        ->and($markdown)->toContain('- Packages: none that imply an architecture.')
        ->and($markdown)->toContain('- Namespaces that suggest layers (classes in each): none.')
        ->and($markdown)->toContain('- Words class names end in (classes each): Data (3).')
        ->and($markdown)->toContain('- Namespaces with many classes and no obvious role: App\Support (5).')
        ->and($markdown)->toContain("```php\n<?php")
        ->and($data['file'])->toBe('sloppy-architecture.php')
        ->and($data['draft'])->toBe(['preset' => 'laravel', 'roles' => ['data' => ['description' => 'Classes named *Data (3 when this was written).', 'suffix' => 'Data']]])
        ->and($data['facts']['unclear'] ?? null)->toBe(['App\Support' => 5])
        ->and($data['format']['properties']['covers'] ?? null)->not->toBeNull();
});

it('keeps a note from ending the comment it is written in', function (): void {
    $proposal = new ProfileProposal(['preset' => 'none'], ["Layer x\nexit('injected'); ?>\r\n<?php echo 1;"]);
    $root = tempProject(['profile.php' => $proposal->php('sloppy architecture import')]);

    expect($proposal->php('sloppy architecture import'))->toContain("// - Layer x exit('injected'); ? >  <?php echo 1;\n")
        ->and(require $root.'/profile.php')->toBe(['preset' => 'none']);

    removeTree($root);
});
