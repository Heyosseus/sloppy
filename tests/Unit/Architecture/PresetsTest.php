<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\Boundaries;
use Heyosseus\Sloppy\Architecture\Presets;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Architecture\ProfileException;
use Heyosseus\Sloppy\Architecture\Role;

it('names the default among its presets, and none last', function (): void {
    expect(Presets::DEFAULT)->toBe('laravel')
        ->and(Presets::names())->toBe(['laravel', 'laravel-actions', 'service-repository', 'ddd', 'hexagonal', 'modular', 'none'])
        ->and(Presets::names()[0])->toBe(Presets::DEFAULT);
});

it('has a definition for every name it lists, and the none preset defines nothing', function (string $name): void {
    $definition = Presets::definition($name);

    expect(array_diff(array_keys($definition), ['roles', 'policies', 'boundaries']))->toBe([]);

    if ($name === 'none') {
        expect($definition)->toBe([]);

        return;
    }

    expect($definition['roles'] ?? [])->not->toBe([]);
})->with(Presets::names());

it('only writes policies for roles the same preset defines', function (string $name): void {
    $definition = Presets::definition($name);

    expect(array_diff(array_keys($definition['policies'] ?? []), array_keys($definition['roles'] ?? [])))->toBe([]);
})->with(Presets::names());

it('loads every preset through the same parser a project goes through, keeping each role and policy', function (string $name): void {
    $definition = Presets::definition($name);
    $profile = Profile::fromArray(['preset' => $name]);

    expect(array_map(static fn (Role $role): string => $role->name, $profile->roles))->toBe(array_keys($definition['roles'] ?? []))
        ->and(array_keys($profile->policies))->toBe(array_keys($definition['policies'] ?? []))
        ->and(! $profile->boundaries instanceof Boundaries)->toBe(! isset($definition['boundaries']));

    foreach ($profile->roles as $role) {
        expect($role->origin)->toBe('preset '.$name);
    }
})->with(Presets::names());

it('shares the Laravel roles, so a preset changes policy rather than classification', function (): void {
    $laravel = Presets::definition('laravel')['roles'] ?? [];

    foreach (['laravel-actions', 'service-repository', 'ddd', 'hexagonal', 'modular'] as $name) {
        $roles = Presets::definition($name)['roles'] ?? [];

        foreach (['form-request', 'controller', 'middleware'] as $shared) {
            expect($roles[$shared] ?? null)->toBe($laravel[$shared], $name.' redefines '.$shared);
        }
    }

    expect(Presets::definition('modular')['roles'] ?? [])->toBe($laravel);
});

it('ships no policies in the default, so declaring nothing reports nothing new', function (): void {
    expect(Presets::definition('laravel'))->not->toHaveKey('policies')
        ->and(Presets::definition('laravel'))->not->toHaveKey('boundaries');
});

it('refuses a preset it does not have, listing the ones it does', function (string $name): void {
    expect(static fn (): array => Presets::definition($name))->toThrow(
        ProfileException::class,
        sprintf('sloppy.architecture.preset [%s] is not a preset. Use one of: laravel, laravel-actions, service-repository, ddd, hexagonal, modular, none.', $name),
    );
})->with(['symfony', '', 'Laravel']);
