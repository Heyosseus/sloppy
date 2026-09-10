<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;

it('parses a severity regardless of casing or padding', function (): void {
    expect(Severity::parse('HIGH'))->toBe(Severity::High)
        ->and(Severity::parse('  medium '))->toBe(Severity::Medium)
        ->and(Severity::parse('Critical'))->toBe(Severity::Critical);
});

it('refuses an unknown severity with a message that lists the options', function (): void {
    expect(fn (): Severity => Severity::parse('urgent'))
        ->toThrow(InvalidArgumentException::class, 'Unknown severity [urgent]');
});

it('ranks critical highest', function (): void {
    $ranks = array_map(static fn (Severity $s): int => $s->rank(), Severity::cases());

    expect($ranks)->toBe([0, 1, 2, 3, 4]);
});

it('answers whether it meets a threshold', function (): void {
    expect(Severity::Critical->isAtLeast(Severity::High))->toBeTrue()
        ->and(Severity::High->isAtLeast(Severity::High))->toBeTrue()
        ->and(Severity::Medium->isAtLeast(Severity::High))->toBeFalse()
        ->and(Severity::Info->isAtLeast(Severity::Info))->toBeTrue();
});

it('weighs more serious findings more heavily', function (): void {
    expect(Severity::Critical->defaultWeight())->toBeGreaterThan(Severity::High->defaultWeight())
        ->and(Severity::High->defaultWeight())->toBeGreaterThan(Severity::Medium->defaultWeight())
        ->and(Severity::Medium->defaultWeight())->toBeGreaterThan(Severity::Low->defaultWeight())
        ->and(Severity::Low->defaultWeight())->toBeGreaterThan(Severity::Info->defaultWeight());
});

it('has a label and a colour for every case', function (): void {
    foreach (Severity::cases() as $severity) {
        expect($severity->label())->toBe(mb_strtoupper($severity->value))
            ->and($severity->color())->not->toBe('');
    }
});

it('labels every category', function (): void {
    foreach (Category::cases() as $category) {
        expect($category->label())->not->toBe('');
    }
});
