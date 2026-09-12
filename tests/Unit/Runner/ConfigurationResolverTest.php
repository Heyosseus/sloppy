<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Runner\ConfigurationResolver;
use Heyosseus\Sloppy\Runner\ScanOptions;
use Heyosseus\Sloppy\Sloppy;

$sloppy = fn (): Sloppy => new Sloppy(Configuration::fromArray([], '/project'));

it('leaves the configuration alone when no overrides are given', function () use ($sloppy): void {
    $resolved = (new ConfigurationResolver)->resolve($sloppy(), new ScanOptions);

    expect($resolved->configuration->paths())->toBe(['app']);
});

it('applies path, threshold and confidence overrides', function () use ($sloppy): void {
    $resolved = (new ConfigurationResolver)->resolve($sloppy(), new ScanOptions(
        paths: ['src'],
        failOn: 'high',
        minConfidence: 70,
    ));

    expect($resolved->configuration->paths())->toBe(['src'])
        ->and($resolved->configuration->failOn())->toBe(Severity::High)
        ->and($resolved->configuration->minConfidence())->toBe(70);
});

it('treats never, none and off as no threshold at all', function () use ($sloppy): void {
    foreach (['never', 'NONE', ' off '] as $value) {
        $resolved = (new ConfigurationResolver)->resolve($sloppy(), new ScanOptions(failOn: $value));

        expect($resolved->configuration->failOn())->toBeNull();
    }
});

it('narrows the registry to the requested rules', function () use ($sloppy): void {
    $resolved = (new ConfigurationResolver)->resolve($sloppy(), new ScanOptions(rules: ['SL101', 'sl107']));

    expect($resolved->rules()->ids())->toBe(['SL101', 'SL107']);
});

it('rejects a confidence floor outside 0 to 100', function () use ($sloppy): void {
    expect(fn (): Sloppy => (new ConfigurationResolver)->resolve($sloppy(), new ScanOptions(minConfidence: -1)))
        ->toThrow(InvalidArgumentException::class, '--min-confidence must be a number between 0 and 100.');
});

it('rejects a confidence floor above 100', function () use ($sloppy): void {
    expect(fn (): Sloppy => (new ConfigurationResolver)->resolve($sloppy(), new ScanOptions(minConfidence: 200)))
        ->toThrow(InvalidArgumentException::class, '--min-confidence must be a number between 0 and 100.');
});
