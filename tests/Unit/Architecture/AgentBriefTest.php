<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Agent\RulesetFormat;
use Heyosseus\Sloppy\Agent\RulesetGenerator;
use Heyosseus\Sloppy\Architecture\AgentBrief;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Sloppy;

const BRIEF_APP = [
    'app/Actions/RefundOrderAction.php' => '<?php namespace App\Actions; class RefundOrderAction {}',
    'app/Actions/CancelOrderAction.php' => '<?php namespace App\Actions; class CancelOrderAction {}',
    'app/Http/Controllers/OrderController.php' => '<?php namespace App\Http\Controllers; class OrderController {}',
    'app/Models/Order.php' => '<?php namespace App\Models; class Order extends \Illuminate\Database\Eloquent\Model {}',
];

it('writes each role with where it lives, what it is called and its policy', function (): void {
    $brief = implode("\n", (new AgentBrief(architectureSnapshotOf(BRIEF_APP, ['preset' => 'laravel-actions', 'covers' => 'app/*', 'boundaries' => ['modules' => 'App\Modules\{module}\*', 'public' => 'Contracts\*', 'shared' => 'App\Modules\Shared\*']])))->markdown());

    expect($brief)->toStartWith("## Architecture of this project\n\nThis project declares its architecture, and Sloppy holds new code to it.")
        ->and($brief)->toContain('`vendor/bin/sloppy architecture place "<what the class does>"`, or the MCP tool `sloppy_place`.')
        ->and($brief)->toContain("### action\n\nOne use case, behind one public method. Lives in `App\\Actions`, and its names end in `Action`.\n- Controllers, jobs and commands call actions;")
        ->and($brief)->toContain("### controller\n\nTurns an HTTP request into a call and a response. Lives in `App\\Http\\Controllers`.\n- A controller hands the work to one action and turns its result into a response.\n- No database reads or database writes.")
        ->and($brief)->toContain("### middleware\n\nWraps requests on their way in and out. No class plays this role yet.")
        ->and($brief)->toContain('Modules are `App\Modules\{module}\*`. A module uses another only through its public surface (Contracts\*, relative to the module) and the shared kernel (`App\Modules\Shared\*`).')
        ->and($brief)->toContain("### Where new classes go\n\nEvery class in `app/*` plays one of the roles above. A new class there that plays none is reported as SL307");
});

it('says so when modules share nothing, and leaves out what is not declared', function (): void {
    $brief = implode("\n", (new AgentBrief(architectureSnapshotOf(BRIEF_APP, ['preset' => 'none', 'roles' => ['action' => ['suffix' => 'Action']], 'boundaries' => ['modules' => 'App\Modules\{module}\*']])))->markdown());

    expect($brief)->toContain('A module uses another only through nothing but the shared kernel.')
        ->and($brief)->toContain("### action\n\nLives in `App\\Actions`, and its names end in `Action`.")
        ->and($brief)->not->toContain('Where new classes go');
});

it('writes the same brief as data', function (): void {
    $data = (new AgentBrief(architectureSnapshotOf(BRIEF_APP, ['preset' => 'laravel-actions'])))->toArray();

    expect($data['preset'])->toBe('laravel-actions')
        ->and($data['source'])->toBe('sloppy.php')
        ->and($data['boundaries'])->toBeNull()
        ->and($data['covers'])->toBe([])
        ->and($data['roles'][4] ?? null)->toMatchArray(['name' => 'action', 'namespace' => 'App\Actions', 'suffix' => 'Action']);
});

it('puts the brief into the rulesets only where the project declared an architecture', function (): void {
    $root = tempProject(BRIEF_APP);
    $plain = RulesetGenerator::for(new Sloppy(Configuration::fromArray(['paths' => ['app']], $root)));
    $declared = RulesetGenerator::for(new Sloppy(Configuration::fromArray(['paths' => ['app'], 'architecture' => ['preset' => 'laravel-actions']], $root)));

    /** @var array<string, mixed> $json */
    $json = json_decode($declared->generate(RulesetFormat::Json), true, flags: JSON_THROW_ON_ERROR);

    expect($plain->generate(RulesetFormat::Claude))->not->toContain('Architecture of this project')
        ->and(json_decode($plain->generate(RulesetFormat::Json), true, flags: JSON_THROW_ON_ERROR))->not->toHaveKey('architecture')
        ->and($declared->generate(RulesetFormat::Claude))->toContain('## Architecture of this project')
        ->and($declared->generate(RulesetFormat::Boost))->toContain('### action')
        ->and($declared->generate(RulesetFormat::Claude))->toContain('### SL308 Role Shape')
        ->and($json)->toHaveKey('architecture');

    removeTree($root);
});

it('says when a profile declares anything beyond the default', function (array $architecture, bool $declared): void {
    expect(Profile::fromArray($architecture)->isDeclared())->toBe($declared);
})->with([
    'nothing' => [[], false],
    'the default preset by name' => [['preset' => 'laravel', 'roles' => [], 'policies' => []], false],
    'another preset' => [['preset' => 'ddd'], true],
    'a role of its own' => [['roles' => ['action' => ['suffix' => 'Action']]], true],
    'a policy' => [['policies' => ['controller' => ['may_not' => 'db']]], true],
    'boundaries' => [['boundaries' => ['modules' => 'App\{module}\*']], true],
    'covered paths' => [['covers' => 'app/*'], true],
]);

it('writes a policy as instructions', function (): void {
    $profile = Profile::fromArray([
        'roles' => ['handler' => ['suffix' => 'Handler']],
        'policies' => [
            'controller' => ['may_depend_on' => ['model', 'App\Support\*'], 'may_not' => ['db', 'http'], 'final' => true],
            'handler' => ['may_depend_on' => [], 'may_not' => 'env', 'public_methods' => ['__invoke']],
            'model' => ['may_not_depend_on' => 'controller'],
        ],
    ]);

    expect($profile->policyFor('controller')?->instructions())->toBe([
        'Depend on no other role than: model, App\Support\*.',
        'No database reads, database writes or outbound HTTP.',
        'Declare the class final.',
    ])
        ->and($profile->policyFor('handler')?->instructions())->toBe(['Depend on no other role.', 'No env() reads.', 'Public methods: __invoke.'])
        ->and($profile->policyFor('model')?->instructions())->toBe(['Never depend on: controller.']);
});

it('names every capability in an instruction', function (): void {
    $policy = Profile::fromArray(['policies' => ['controller' => ['may_not' => ['db', 'http', 'dispatch', 'request', 'env', 'view', 'container']]]])->policyFor('controller');

    expect($policy?->instructions())->toBe([
        'No database reads, database writes, outbound HTTP, dispatching jobs, events, notifications or mail, reading the HTTP request, env() reads, rendering views or resolving from the service container.',
    ]);
});
