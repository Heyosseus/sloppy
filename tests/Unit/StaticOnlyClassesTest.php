<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Drift\BoundedTokenDistance;
use Heyosseus\Sloppy\Analysis\Drift\MaskedDivergence;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Coverage\CloverReader;
use Heyosseus\Sloppy\Coverage\CoberturaReader;
use Heyosseus\Sloppy\Evidence\BaselineEntries;
use Heyosseus\Sloppy\Integrations\Tooling\RectorRules;
use Heyosseus\Sloppy\Rules\Laravel\LaravelCalls;
use Heyosseus\Sloppy\Support\StringListOption;

/**
 * The classes that are deliberately not objects.
 *
 * Each of these is a namespace for functions: it holds no state, every method
 * is static, and a private constructor says so rather than leaving it to a
 * naming convention. That is a design decision worth a test, because the way it
 * gets lost is somebody adding a property and a public constructor to "just
 * one" of them and nobody noticing the class now has two lives.
 */
$staticOnly = [
    BoundedTokenDistance::class,
    MaskedDivergence::class,
    NodeHelper::class,
    CloverReader::class,
    CoberturaReader::class,
    BaselineEntries::class,
    RectorRules::class,
    LaravelCalls::class,
    StringListOption::class,
];

it('keeps every static-only helper impossible to instantiate', function (string $class): void {
    $constructor = (new ReflectionClass($class))->getConstructor();

    expect($constructor)->not->toBeNull()
        ->and($constructor?->isPrivate())->toBeTrue();
})->with($staticOnly);

it('gives every static-only helper a constructor that does nothing', function (string $class): void {
    // Invoked through reflection because nothing else can reach it, and the
    // point is that reaching it achieves nothing: the body is empty, so an
    // instance carries no state a static call could depend on.
    $reflection = new ReflectionClass($class);
    $constructor = $reflection->getConstructor();
    $instance = $reflection->newInstanceWithoutConstructor();

    $constructor?->invoke($instance);

    expect(get_object_vars($instance))->toBe([]);
})->with($staticOnly);

it('declares no instance methods on a static-only helper', function (string $class): void {
    $instanceMethods = array_values(array_filter(
        (new ReflectionClass($class))->getMethods(),
        static fn (ReflectionMethod $method): bool => ! $method->isStatic() && ! $method->isConstructor(),
    ));

    expect(array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $instanceMethods,
    ))->toBe([]);
})->with($staticOnly);
