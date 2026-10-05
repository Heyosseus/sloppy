<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\AllOf;
use Heyosseus\Sloppy\Architecture\AnyOf;
use Heyosseus\Sloppy\Architecture\ClassFacts;
use Heyosseus\Sloppy\Architecture\MatchKey;
use Heyosseus\Sloppy\Architecture\NotMatcher;
use Heyosseus\Sloppy\Architecture\PatternMatcher;
use Heyosseus\Sloppy\Architecture\ProfileException;
use Heyosseus\Sloppy\Architecture\Role;
use Heyosseus\Sloppy\Architecture\RoleParser;

/**
 * The facts of one class, with everything a test does not name left empty.
 *
 * @param  'class'|'interface'|'trait'|'enum'  $kind
 */
function roleFacts(string $fqn, string $kind = 'class', string $path = 'app/Example.php', ?string $parent = null): ClassFacts
{
    $short = str_contains($fqn, '\\') ? substr($fqn, (int) strrpos($fqn, '\\') + 1) : $fqn;

    return new ClassFacts($fqn, $short, $kind, $path, $parent, $parent === null ? [] : [$parent], [], [], []);
}

function parsedRole(mixed $definition): Role
{
    return RoleParser::parse('action', $definition, 'sloppy.php', 'sloppy.architecture.roles.action');
}

it('reads a single key as one pattern matcher, with its name, origin and defaults', function (): void {
    $role = parsedRole(['suffix' => 'Action']);

    expect($role->name)->toBe('action')
        ->and($role->origin)->toBe('sloppy.php')
        ->and($role->description)->toBeNull()
        ->and($role->intendedAbstraction)->toBeFalse()
        ->and($role->matcher)->toBeInstanceOf(PatternMatcher::class)
        ->and($role->matcher instanceof PatternMatcher ? $role->matcher->key : null)->toBe(MatchKey::Suffix)
        ->and($role->matcher instanceof PatternMatcher ? $role->matcher->patterns : null)->toBe(['Action']);
});

it('requires every key of one definition to hold', function (): void {
    $role = parsedRole(['suffix' => 'Action', 'kind' => 'class']);

    expect($role->matcher)->toBeInstanceOf(AllOf::class)
        ->and($role->matches(roleFacts('App\RefundAction')))->toBeTrue()
        ->and($role->matches(roleFacts('App\RefundAction', 'interface')))->toBeFalse()
        ->and($role->matches(roleFacts('App\Refund')))->toBeFalse();
});

it('takes a list of patterns of which any may match, trimmed', function (): void {
    $role = parsedRole(['suffix' => [' Action ', 'UseCase']]);

    expect($role->matcher instanceof PatternMatcher ? $role->matcher->patterns : null)->toBe(['Action', 'UseCase'])
        ->and($role->matches(roleFacts('App\PlaceOrderUseCase')))->toBeTrue()
        ->and($role->matches(roleFacts('App\PlaceOrderAction')))->toBeTrue()
        ->and($role->matches(roleFacts('App\PlaceOrder')))->toBeFalse();
});

it('reads any as alternatives and not as an exclusion, nested as deep as written', function (): void {
    $role = parsedRole([
        'any' => [
            ['suffix' => 'Action', 'not' => ['suffix' => 'TestAction']],
            ['namespace' => 'App\Actions\*'],
        ],
    ]);

    expect($role->matcher)->toBeInstanceOf(AnyOf::class)
        ->and($role->matcher instanceof AnyOf ? $role->matcher->matchers[0] : null)->toBeInstanceOf(AllOf::class)
        ->and($role->matches(roleFacts('App\RefundAction')))->toBeTrue()
        ->and($role->matches(roleFacts('App\Actions\Refund')))->toBeTrue()
        ->and($role->matches(roleFacts('App\FakeTestAction')))->toBeFalse()
        ->and($role->matches(roleFacts('App\Refund')))->toBeFalse();
});

it('reads a lone not as the whole matcher', function (): void {
    $role = parsedRole(['not' => ['kind' => 'interface']]);

    expect($role->matcher)->toBeInstanceOf(NotMatcher::class)
        ->and($role->matches(roleFacts('App\Refund')))->toBeTrue()
        ->and($role->matches(roleFacts('App\Refund', 'interface')))->toBeFalse();
});

it('keeps the description and the intended-abstraction flag out of the matchers', function (): void {
    $role = parsedRole(['description' => 'One use case.', 'intended_abstraction' => true, 'kind' => 'interface']);

    expect($role->description)->toBe('One use case.')
        ->and($role->intendedAbstraction)->toBeTrue()
        ->and($role->matcher)->toBeInstanceOf(PatternMatcher::class)
        ->and($role->matches(roleFacts('App\Gateway', 'interface')))->toBeTrue();
});

it('accepts every kind and every match key it advertises', function (): void {
    foreach (MatchKey::KINDS as $kind) {
        expect(parsedRole(['kind' => $kind])->matches(roleFacts('App\X', $kind)))->toBeTrue();
    }

    foreach (MatchKey::cases() as $key) {
        $value = $key === MatchKey::Kind ? 'class' : 'App\*';

        expect(parsedRole([$key->value => $value])->matcher)->toBeInstanceOf(PatternMatcher::class);
    }
});

it('refuses a definition it cannot use, naming where it sits', function (mixed $definition, string $message): void {
    expect(static fn (): Role => parsedRole($definition))->toThrow(ProfileException::class, $message);
})->with([
    'not an array' => ['Action', 'sloppy.architecture.roles.action must be an array of matchers, or false to remove a preset role.'],
    'true rather than a definition' => [true, 'sloppy.architecture.roles.action must be an array of matchers'],
    'nothing to match' => [[], 'sloppy.architecture.roles.action has no matchers, so it would match nothing. Give it at least one of: namespace, path, suffix, parent, extends, implements, uses, attribute, kind, any, not.'],
    'only a description' => [['description' => 'x', 'intended_abstraction' => false], 'sloppy.architecture.roles.action has no matchers'],
    'a list instead of keys' => [[['suffix' => 'Action']], 'sloppy.architecture.roles.action has an unknown key [0].'],
    'a description that is not a string' => [['suffix' => 'Action', 'description' => ['x']], 'sloppy.architecture.roles.action.description must be a string.'],
    'intended that is not a boolean' => [['suffix' => 'Action', 'intended_abstraction' => 1], 'sloppy.architecture.roles.action.intended_abstraction must be true or false.'],
    'a pattern map' => [['suffix' => ['a' => 'Action']], 'sloppy.architecture.roles.action.suffix must be a string or a non-empty list of strings.'],
    'a pattern that is a number' => [['suffix' => 7], 'sloppy.architecture.roles.action.suffix must be a string or a non-empty list of strings.'],
    'a blank pattern in a list' => [['namespace' => ['App\*', '']], 'sloppy.architecture.roles.action.namespace must be a string or a non-empty list of strings.'],
    'a kind spelt in capitals' => [['kind' => 'Class'], 'sloppy.architecture.roles.action.kind has an unknown kind [Class].'],
    'an empty any' => [['any' => []], 'sloppy.architecture.roles.action.any must be a non-empty list of matcher arrays.'],
    'a bad key inside any' => [['any' => [['suffix' => 'A'], ['sufix' => 'B']]], 'sloppy.architecture.roles.action.any.1 has an unknown key [sufix].'],
    'an empty not' => [['not' => []], 'sloppy.architecture.roles.action.not has no matchers'],
    'a bad key inside not' => [['suffix' => 'A', 'not' => ['kinds' => 'interface']], 'sloppy.architecture.roles.action.not has an unknown key [kinds].'],
]);
