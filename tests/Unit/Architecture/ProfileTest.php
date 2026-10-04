<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Architecture\ProfileException;
use Heyosseus\Sloppy\Architecture\Role;
use Heyosseus\Sloppy\Configuration\Configuration;

/**
 * @return list<string>
 */
function roleNames(Profile $profile): array
{
    return array_map(static fn (Role $role): string => $role->name, $profile->roles);
}

it('defaults to the Laravel preset, most specific role first', function (): void {
    $profile = Profile::default();

    expect($profile->preset)->toBe('laravel')
        ->and(roleNames($profile))->toBe(['form-request', 'model', 'controller', 'middleware', 'service'])
        ->and($profile->role('controller')?->origin)->toBe('preset laravel')
        ->and($profile->role('controller')?->description)->toBe('Turns an HTTP request into a call and a response.')
        ->and($profile->role('nonsense'))->toBeNull();
});

it('tries the project\'s own roles first, in the order written', function (): void {
    $profile = Profile::fromArray(['roles' => [
        'action' => ['suffix' => 'Action'],
        'query' => ['suffix' => 'Query'],
    ]]);

    expect(roleNames($profile))->toBe(['action', 'query', 'form-request', 'model', 'controller', 'middleware', 'service'])
        ->and($profile->role('action')?->origin)->toBe('sloppy.php');
});

it('lets a project replace a preset role, or remove one with false', function (): void {
    $profile = Profile::fromArray(['roles' => [
        'controller' => ['namespace' => 'App\Ui\*'],
        'service' => false,
    ]]);

    expect(roleNames($profile))->toBe(['controller', 'form-request', 'model', 'middleware'])
        ->and($profile->role('controller')?->origin)->toBe('sloppy.php');
});

it('starts from nothing with the none preset, and reads the preset name loosely', function (): void {
    expect(Profile::fromArray(['preset' => 'none'])->roles)->toBe([])
        ->and(Profile::fromArray(['preset' => ' Laravel '])->preset)->toBe('laravel');
});

it('reads sloppy.architecture from the configuration', function (): void {
    $configured = Configuration::fromArray(['architecture' => ['preset' => 'none', 'roles' => ['action' => ['suffix' => 'Action']]]], '/p');

    expect(roleNames($configured->architecture()))->toBe(['action'])
        ->and(Configuration::fromArray([], '/p')->architecture()->preset)->toBe('laravel');
});

it('refuses an architecture it cannot use, naming the key and the fix', function (array $architecture, string $message): void {
    expect(static fn (): Profile => Profile::fromArray($architecture))
        ->toThrow(ProfileException::class, $message);
})->with([
    'an unknown top-level key' => [['presets' => 'laravel'], 'sloppy.architecture has an unknown key [presets]. Use any of: preset, roles, policies, boundaries.'],
    'an unknown preset' => [['preset' => 'symfony'], 'sloppy.architecture.preset [symfony] is not a preset. Use one of: laravel, laravel-actions, service-repository, ddd, hexagonal, modular, none.'],
    'a preset that is not a string' => [['preset' => ['laravel']], 'sloppy.architecture.preset must be a string'],
    'roles as a list' => [['roles' => [['suffix' => 'Action']]], 'sloppy.architecture.roles must map role names to definitions'],
    'roles as a string' => [['roles' => 'action'], 'sloppy.architecture.roles must map role names to definitions'],
    'a badly named role' => [['roles' => ['Action' => ['suffix' => 'Action']]], 'has a role named [Action]. Role names are lower-case words joined by hyphens'],
    'a role that is not an array' => [['roles' => ['action' => 'Action']], 'sloppy.architecture.roles.action must be an array of matchers, or false to remove a preset role.'],
    'a role with no matchers' => [['roles' => ['action' => ['description' => 'x']]], 'sloppy.architecture.roles.action has no matchers, so it would match nothing.'],
    'a misspelt matcher' => [['roles' => ['action' => ['sufix' => 'Action']]], 'sloppy.architecture.roles.action has an unknown key [sufix]. Use one of: namespace, path, suffix, parent, extends, implements, uses, attribute, kind, any, not.'],
    'an empty pattern' => [['roles' => ['action' => ['suffix' => ' ']]], 'sloppy.architecture.roles.action.suffix must be a string or a non-empty list of strings.'],
    'an empty pattern list' => [['roles' => ['action' => ['suffix' => []]]], 'sloppy.architecture.roles.action.suffix must be a string or a non-empty list of strings.'],
    'a pattern that is not a string' => [['roles' => ['action' => ['suffix' => [1]]]], 'sloppy.architecture.roles.action.suffix must be a string or a non-empty list of strings.'],
    'an unknown kind' => [['roles' => ['action' => ['kind' => 'struct']]], 'sloppy.architecture.roles.action.kind has an unknown kind [struct]. Use one of: class, interface, trait, enum.'],
    'any that is not a list' => [['roles' => ['action' => ['any' => ['suffix' => 'Action']]]], 'sloppy.architecture.roles.action.any must be a non-empty list of matcher arrays.'],
    'an alternative that is not an array' => [['roles' => ['action' => ['any' => ['Action']]]], 'sloppy.architecture.roles.action.any.0 must be an array of matchers.'],
    'an empty alternative' => [['roles' => ['action' => ['any' => [[]]]]], 'sloppy.architecture.roles.action.any.0 has no matchers'],
    'not that is not an array' => [['roles' => ['action' => ['not' => 'Test']]], 'sloppy.architecture.roles.action.not must be an array of matchers.'],
    'a description that is not a string' => [['roles' => ['action' => ['suffix' => 'Action', 'description' => 1]]], 'sloppy.architecture.roles.action.description must be a string.'],
]);

it('refuses an architecture setting that is not an array', function (): void {
    expect(static fn (): Profile => Configuration::fromArray(['architecture' => 'laravel'], '/p')->architecture())
        ->toThrow(ProfileException::class, 'sloppy.architecture must be an array with a preset, roles, or both.');
});
