<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Evidence\TestBody;
use Heyosseus\Sloppy\Evidence\TestInventory;

it('carries exactly what it was given', function (): void {
    $body = new TestBody(name: 'OrderTest::test_total', line: 12, assertions: 3, skipped: true, trivial: 1, hash: 'abc');

    expect($body->name)->toBe('OrderTest::test_total')
        ->and($body->line)->toBe(12)
        ->and($body->assertions)->toBe(3)
        ->and($body->skipped)->toBeTrue()
        ->and($body->trivial)->toBe(1)
        ->and($body->hash)->toBe('abc');
});

it('cannot be changed once built', function (): void {
    $reflection = new ReflectionClass(TestBody::class);

    expect($reflection->isReadOnly())->toBeTrue()
        ->and($reflection->isFinal())->toBeTrue();
});

it('is what the inventory builds, one per test, keyed by its own name', function (): void {
    $tests = TestInventory::of(parsedFile("it('works', function () { expect(\$a)->toBe(1); });"));

    expect($tests)->toHaveCount(1)
        ->and($tests['it works'])->toBeInstanceOf(TestBody::class)
        ->and($tests['it works']->name)->toBe('it works')
        ->and($tests['it works']->line)->toBe(3)
        ->and($tests['it works']->assertions)->toBe(1)
        ->and($tests['it works']->trivial)->toBe(0)
        ->and($tests['it works']->skipped)->toBeFalse();
});
