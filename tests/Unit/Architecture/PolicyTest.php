<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\Capability;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Architecture\ProfileException;

it('reads a policy: what a role may and may not depend on, and may not do', function (): void {
    $policy = Profile::fromArray(['policies' => ['controller' => [
        'may_depend_on' => ['service', 'App\Support\*'],
        'may_not_depend_on' => ['model'],
        'may_not' => ['db', 'env', 'db.read'],
        'advice' => ' Controllers call services. ',
    ]]])->policyFor('controller');

    expect($policy?->origin)->toBe('sloppy.php')
        ->and($policy?->mayNot)->toBe([Capability::DatabaseRead, Capability::DatabaseWrite, Capability::Env])
        ->and($policy?->advice)->toBe('Controllers call services.')
        ->and($policy?->constrainsDependencies())->toBeTrue()
        ->and($policy?->forbids(Capability::Env))->toBeTrue()
        ->and($policy?->forbids(Capability::Http))->toBeFalse();
});

it('forbids what may_not_depend_on names, by role or by glob', function (): void {
    $policy = Profile::fromArray(['policies' => ['service' => [
        'may_not_depend_on' => ['controller', 'Illuminate\Http\*'],
    ]]])->policyFor('service');

    expect($policy?->dependencyViolation('App\Http\Controllers\X', 'controller'))->toBe('classes in the service role may not depend on controller')
        ->and($policy?->dependencyViolation('Illuminate\Http\Request', null))->toBe('classes in the service role may not depend on Illuminate\Http\*')
        ->and($policy?->dependencyViolation('App\Models\Order', 'model'))->toBeNull();
});

it('judges only classes that play a role by the allow list, and always allows the role itself', function (): void {
    $policy = Profile::fromArray(['policies' => ['controller' => [
        'may_depend_on' => ['service', 'App\Support\*'],
    ]]])->policyFor('controller');

    expect($policy?->dependencyViolation('App\Models\Order', 'model'))->toBe('classes in the controller role may depend only on service, App\Support\*')
        ->and($policy?->dependencyViolation('App\Services\Billing', 'service'))->toBeNull()
        ->and($policy?->dependencyViolation('App\Support\Clock', 'model'))->toBeNull()
        ->and($policy?->dependencyViolation('App\Http\Controllers\Base', 'controller'))->toBeNull()
        ->and($policy?->dependencyViolation('Illuminate\Http\Request', null))->toBeNull();
});

it('takes a preset\'s policies, lets a project replace or remove one, and drops one whose role is gone', function (): void {
    $preset = Profile::fromArray(['preset' => 'hexagonal']);
    $tuned = Profile::fromArray(['preset' => 'hexagonal', 'policies' => [
        'controller' => false,
        'application' => ['may_not' => ['env']],
    ], 'roles' => ['port' => false]]);

    expect(array_keys($preset->policies))->toBe(['domain', 'port', 'application', 'controller'])
        ->and($preset->policyFor('domain')?->origin)->toBe('preset hexagonal')
        ->and(array_keys($tuned->policies))->toBe(['application', 'domain'])
        ->and($tuned->policyFor('application')?->mayNot)->toBe([Capability::Env])
        ->and($tuned->policyFor('application')?->origin)->toBe('sloppy.php')
        ->and($tuned->policyFor(null))->toBeNull();
});

it('says whether anything is declared for the policy rules to enforce', function (): void {
    $default = Profile::default();
    $capabilities = Profile::fromArray(['policies' => ['controller' => ['may_not' => ['db']]]]);
    $dependencies = Profile::fromArray(['policies' => ['controller' => ['may_not_depend_on' => ['model']]]]);

    expect($default->constrainsDependencies())->toBeFalse()
        ->and($default->constrainsCapabilities())->toBeFalse()
        ->and($default->boundaries)->toBeNull()
        ->and($capabilities->constrainsCapabilities())->toBeTrue()
        ->and($capabilities->constrainsDependencies())->toBeFalse()
        ->and($dependencies->constrainsDependencies())->toBeTrue()
        ->and($dependencies->constrainsCapabilities())->toBeFalse();
});

it('reads boundaries, takes a preset\'s, and turns them off with false', function (): void {
    $boundaries = Profile::fromArray(['boundaries' => [
        'modules' => 'App\Modules\{module}\*',
        'public' => 'Contracts\*',
        'shared' => ['App\Modules\Shared\*'],
    ]])->boundaries;

    expect($boundaries?->origin)->toBe('sloppy.php')
        ->and($boundaries?->modules)->toBe(['App\Modules\{module}\*'])
        ->and($boundaries?->publicSurface())->toBe('Contracts\*')
        ->and(Profile::fromArray(['preset' => 'modular'])->boundaries?->origin)->toBe('preset modular')
        ->and(Profile::fromArray(['preset' => 'modular', 'boundaries' => false])->boundaries)->toBeNull();
});

it('ships every preset in a form it can load', function (string $preset): void {
    $profile = Profile::fromArray(['preset' => $preset]);

    expect($profile->preset)->toBe($preset);
})->with(['laravel', 'laravel-actions', 'service-repository', 'ddd', 'hexagonal', 'modular', 'none']);

it('marks the abstractions a preset intends', function (): void {
    $hexagonal = Profile::fromArray(['preset' => 'hexagonal']);

    expect($hexagonal->role('port')?->intendedAbstraction)->toBeTrue()
        ->and($hexagonal->role('adapter')?->intendedAbstraction)->toBeTrue()
        ->and($hexagonal->role('domain')?->intendedAbstraction)->toBeFalse();
});

it('refuses a policy or boundary it cannot use, naming the key and the fix', function (array $architecture, string $message): void {
    expect(static fn (): Profile => Profile::fromArray($architecture))
        ->toThrow(ProfileException::class, $message);
})->with([
    'policies as a list' => [['policies' => [['may_not' => ['db']]]], 'sloppy.architecture.policies must map role names to definitions'],
    'a badly named policy' => [['policies' => ['Controller' => ['may_not' => ['db']]]], 'sloppy.architecture.policies has a role named [Controller].'],
    'a policy for a missing role' => [['policies' => ['action' => ['may_not' => ['db']]]], 'sloppy.architecture.policies.action is a policy for a role that does not exist. Roles: form-request, model, controller, middleware, service.'],
    'an empty policy' => [['policies' => ['controller' => []]], 'sloppy.architecture.policies.controller must be an array with any of: may_depend_on, may_not_depend_on, may_not, advice.'],
    'a misspelt policy key' => [['policies' => ['controller' => ['may_dependon' => ['model']]]], 'sloppy.architecture.policies.controller has an unknown key [may_dependon]. Use any of: may_depend_on, may_not_depend_on, may_not, advice.'],
    'a target that is not a role' => [['policies' => ['controller' => ['may_not_depend_on' => ['repository']]]], 'sloppy.architecture.policies.controller.may_not_depend_on names [repository], which is not a role.'],
    'a target that is not a string' => [['policies' => ['controller' => ['may_depend_on' => [1]]]], 'sloppy.architecture.policies.controller.may_depend_on must be a string or a list of strings.'],
    'an unknown capability' => [['policies' => ['controller' => ['may_not' => ['telepathy']]]], 'sloppy.architecture.policies.controller.may_not has an unknown capability [telepathy]. Use any of: db, db.read, db.write, http, dispatch, request, env, view, container.'],
    'no capabilities' => [['policies' => ['controller' => ['may_not' => []]]], 'sloppy.architecture.policies.controller.may_not must be a string or a list of strings.'],
    'empty advice' => [['policies' => ['controller' => ['may_not' => 'db', 'advice' => ' ']]], 'sloppy.architecture.policies.controller.advice must be a sentence.'],
    'boundaries as a list' => [['boundaries' => ['App\Modules\{module}\*']], 'sloppy.architecture.boundaries must be an array with modules, and optionally public and shared.'],
    'boundaries without modules' => [['boundaries' => ['public' => ['Contracts\*']]], 'sloppy.architecture.boundaries.modules must be a string or a list of strings.'],
    'a module pattern without a capture' => [['boundaries' => ['modules' => 'App\Modules\*']], 'sloppy.architecture.boundaries.modules [App\Modules\*] must contain {module} exactly once'],
    'a misspelt boundary key' => [['boundaries' => ['modules' => 'App\{module}\*', 'internal' => []]], 'sloppy.architecture.boundaries has an unknown key [internal]. Use any of: modules, public, shared.'],
    'intended that is not a boolean' => [['roles' => ['port' => ['kind' => 'interface', 'intended_abstraction' => 'yes']]], 'sloppy.architecture.roles.port.intended_abstraction must be true or false.'],
]);
