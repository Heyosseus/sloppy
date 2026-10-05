<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\Boundaries;
use Heyosseus\Sloppy\Architecture\Capability;
use Heyosseus\Sloppy\Architecture\DependencyTarget;
use Heyosseus\Sloppy\Architecture\Glob;
use Heyosseus\Sloppy\Architecture\Policy;
use Heyosseus\Sloppy\Architecture\PolicyParser;
use Heyosseus\Sloppy\Architecture\ProfileException;

const PARSER_ROLES = ['controller', 'service', 'model'];

function parsedPolicy(mixed $definition): Policy
{
    return PolicyParser::policy('controller', $definition, 'sloppy.php', 'sloppy.architecture.policies.controller', PARSER_ROLES);
}

/**
 * @param  list<DependencyTarget>|null  $targets
 * @return list<string>|null
 */
function describedTargets(?array $targets): ?array
{
    return $targets === null ? null : array_map(static fn (DependencyTarget $target): string => $target->describe(), $targets);
}

/**
 * @param  list<Glob>  $globs
 * @return list<string>
 */
function globPatterns(array $globs): array
{
    return array_map(static fn (Glob $glob): string => $glob->pattern, $globs);
}

it('reads every key of a policy into the value object', function (): void {
    $policy = parsedPolicy([
        'may_depend_on' => ['service', 'App\Support\*'],
        'may_not_depend_on' => 'model',
        'may_not' => ['env', 'view'],
        'public_methods' => ['__invoke', 'show*'],
        'final' => true,
        'advice' => '  Hand the work to a service.  ',
    ]);

    expect($policy->role)->toBe('controller')
        ->and($policy->origin)->toBe('sloppy.php')
        ->and(describedTargets($policy->mayDependOn))->toBe(['service', 'App\Support\*'])
        ->and(describedTargets($policy->mayNotDependOn))->toBe(['model'])
        ->and($policy->mayNot)->toBe([Capability::Env, Capability::View])
        ->and(globPatterns($policy->publicMethods ?? []))->toBe(['__invoke', 'show*'])
        ->and($policy->final)->toBeTrue()
        ->and($policy->advice)->toBe('Hand the work to a service.');
});

it('leaves what a policy does not say unconstrained', function (): void {
    $policy = parsedPolicy(['advice' => 'Keep it thin.']);

    // Null, not an empty list: an absent allow list allows everything, while
    // an empty one allows only the role itself.
    expect($policy->mayDependOn)->toBeNull()
        ->and($policy->mayNotDependOn)->toBe([])
        ->and($policy->mayNot)->toBe([])
        ->and($policy->publicMethods)->toBeNull()
        ->and($policy->final)->toBeFalse()
        ->and($policy->constrainsDependencies())->toBeFalse()
        ->and($policy->constrainsShape())->toBeFalse();
});

it('reads an empty allow list as the role on its own, and empty public methods as magic methods only', function (): void {
    $policy = parsedPolicy(['may_depend_on' => [], 'public_methods' => []]);

    expect($policy->mayDependOn)->toBe([])
        ->and($policy->dependencyViolation('App\Services\Billing', 'service'))->not->toBeNull()
        ->and($policy->dependencyViolation('App\Http\Controllers\Other', 'controller'))->toBeNull()
        ->and($policy->publicMethods)->toBe([])
        ->and($policy->allowsPublicMethod('__construct'))->toBeTrue()
        ->and($policy->allowsPublicMethod('show'))->toBeFalse();
});

it('tells a role from a class glob by its spelling', function (): void {
    $policy = parsedPolicy(['may_not_depend_on' => ['service', 'Illuminate\*', 'Stripe', 'vendor\sdk\*']]);

    expect(array_map(static fn (DependencyTarget $target): ?string => $target->role, $policy->mayNotDependOn))
        ->toBe(['service', null, null, null])
        ->and($policy->mayNotDependOn[1]->matches('Illuminate\Support\Collection', null))->toBeTrue()
        ->and($policy->mayNotDependOn[2]->matches('Stripe', null))->toBeTrue()
        ->and($policy->mayNotDependOn[0]->matches('App\Services\Billing', 'service'))->toBeTrue();
});

it('expands db to both directions, reads capabilities loosely and counts each once', function (): void {
    expect(parsedPolicy(['may_not' => ' DB '])->mayNot)->toBe([Capability::DatabaseRead, Capability::DatabaseWrite])
        ->and(parsedPolicy(['may_not' => ['db.read', 'db', 'db.*']])->mayNot)->toBe([Capability::DatabaseRead, Capability::DatabaseWrite])
        ->and(parsedPolicy(['may_not' => ['http', 'Http']])->mayNot)->toBe([Capability::Http]);
});

it('reads boundaries, with the public surface and shared kernel optional', function (): void {
    $boundaries = PolicyParser::boundaries(['modules' => ' App\Modules\{module}\* '], 'sloppy.php', 'sloppy.architecture.boundaries');

    expect($boundaries)->toBeInstanceOf(Boundaries::class)
        ->and($boundaries->origin)->toBe('sloppy.php')
        ->and($boundaries->modules)->toBe(['App\Modules\{module}\*'])
        ->and($boundaries->public)->toBe([])
        ->and($boundaries->shared)->toBe([])
        ->and($boundaries->describe())->toBe('modules App\Modules\{module}\*; public nothing; shared nothing');

    $full = PolicyParser::boundaries([
        'modules' => ['Modules\{module}\*', 'App\Modules\{module}\*'],
        'public' => 'Contracts\*',
        'shared' => ['App\Modules\Shared\*'],
    ], 'preset modular', 'preset modular: boundaries');

    expect(globPatterns($full->public))->toBe(['Contracts\*'])
        ->and(globPatterns($full->shared))->toBe(['App\Modules\Shared\*'])
        ->and($full->violation('App\Modules\Billing\Invoice', 'App\Modules\Orders\Internal\Repo'))->toContain('internal to the Orders module')
        ->and($full->violation('App\Modules\Billing\Invoice', 'App\Modules\Orders\Contracts\Orders'))->toBeNull();
});

it('reads globs from a string or a list, and allows none', function (): void {
    expect(globPatterns(PolicyParser::globs('handle', 'p')))->toBe(['handle'])
        ->and(globPatterns(PolicyParser::globs([' as* ', 'get*'], 'p')))->toBe(['as*', 'get*'])
        ->and(PolicyParser::globs([], 'p'))->toBe([]);
});

it('refuses a policy it cannot use, naming where it sits', function (mixed $definition, string $message): void {
    expect(static fn (): Policy => parsedPolicy($definition))->toThrow(ProfileException::class, $message);
})->with([
    'not an array' => ['db', 'sloppy.architecture.policies.controller must be an array with any of: may_depend_on, may_not_depend_on, may_not, public_methods, final, advice.'],
    'empty' => [[], 'sloppy.architecture.policies.controller must be an array with any of'],
    'a list' => [[['may_not' => 'db']], 'sloppy.architecture.policies.controller must be an array with any of'],
    'an unknown key' => [['may_not' => 'db', 'maynot' => 'env'], 'sloppy.architecture.policies.controller has an unknown key [maynot]. Use any of: may_depend_on, may_not_depend_on, may_not, public_methods, final, advice.'],
    'advice that is not a string' => [['advice' => ['x']], 'sloppy.architecture.policies.controller.advice must be a sentence.'],
    'blank advice' => [['advice' => "\n"], 'sloppy.architecture.policies.controller.advice must be a sentence.'],
    'final as a string' => [['final' => 'true'], 'sloppy.architecture.policies.controller.final must be true or false.'],
    'a role that does not exist' => [['may_depend_on' => ['repository']], 'sloppy.architecture.policies.controller.may_depend_on names [repository], which is not a role. Roles: controller, service, model. To name classes rather than a role, use a glob such as "App\Support\*".'],
    'a target map' => [['may_not_depend_on' => ['a' => 'model']], 'sloppy.architecture.policies.controller.may_not_depend_on must be a string or a list of strings.'],
    'a blank target' => [['may_not_depend_on' => ['model', ' ']], 'sloppy.architecture.policies.controller.may_not_depend_on must be a string or a list of strings.'],
    'no capabilities' => [['may_not' => []], 'sloppy.architecture.policies.controller.may_not must be a string or a list of strings.'],
    'an unknown capability' => [['may_not' => ['db', 'email']], 'sloppy.architecture.policies.controller.may_not has an unknown capability [email]. Use any of: db, db.read, db.write, http, dispatch, request, env, view, container.'],
    'public methods as a number' => [['public_methods' => 3], 'sloppy.architecture.policies.controller.public_methods must be a string or a list of strings.'],
]);

it('refuses boundaries it cannot use, naming where they sit', function (mixed $definition, string $message): void {
    expect(static fn (): Boundaries => PolicyParser::boundaries($definition, 'sloppy.php', 'sloppy.architecture.boundaries'))
        ->toThrow(ProfileException::class, $message);
})->with([
    'not an array' => ['App\{module}\*', 'sloppy.architecture.boundaries must be an array with modules, and optionally public and shared.'],
    'a list' => [['App\{module}\*'], 'sloppy.architecture.boundaries must be an array with modules'],
    'no modules' => [['shared' => 'App\Shared\*'], 'sloppy.architecture.boundaries.modules must be a string or a list of strings.'],
    'empty modules' => [['modules' => []], 'sloppy.architecture.boundaries.modules must be a string or a list of strings.'],
    'a module pattern without a capture' => [['modules' => 'App\Modules\*'], 'sloppy.architecture.boundaries.modules [App\Modules\*] must contain {module} exactly once, such as "App\Modules\{module}\*".'],
    'a module pattern capturing twice' => [['modules' => '{module}\{module}\*'], 'must contain {module} exactly once'],
    'an unknown key' => [['modules' => 'App\{module}\*', 'private' => []], 'sloppy.architecture.boundaries has an unknown key [private]. Use any of: modules, public, shared.'],
    'a public surface that is not strings' => [['modules' => 'App\{module}\*', 'public' => [1]], 'sloppy.architecture.boundaries.public must be a string or a list of strings.'],
    'a shared kernel that is a map' => [['modules' => 'App\{module}\*', 'shared' => ['x' => 'App\Shared\*']], 'sloppy.architecture.boundaries.shared must be a string or a list of strings.'],
]);
