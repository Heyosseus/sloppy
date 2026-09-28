<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\RuleRegistry;
use Heyosseus\Sloppy\Analysis\Tier;
use Heyosseus\Sloppy\Analysis\TierMap;
use Heyosseus\Sloppy\Configuration\Configuration;

it('lists what can go wrong as defects, and how the code reads as maintainability', function (): void {
    $tiers = [];

    foreach (RuleRegistry::withDefaults()->rules() as $rule) {
        $tiers[$rule->id()] = Tier::defaultFor($rule->category())->value;
    }

    expect(array_keys(array_filter($tiers, static fn (string $tier): bool => $tier === 'defect')))
        ->toBe(['SL105', 'SL107', 'SL112', 'SL501', 'SL203', 'SL204', 'SL205', 'SL210'])
        ->and(array_keys(array_filter($tiers, static fn (string $tier): bool => $tier === 'advisory')))
        ->toBe(['SL301', 'SL302', 'SL303'])
        ->and($tiers['SL101'])->toBe('maintainability')
        ->and($tiers['SL109'])->toBe('maintainability');
});

it('parses a tier whatever its case, and refuses one that does not exist', function (): void {
    expect(Tier::parse(' Defect '))->toBe(Tier::Defect)
        ->and(Tier::Maintainability->label())->toBe('Maintainability')
        ->and(Tier::Advisory->label())->toBe('Advisory')
        ->and(Tier::Defect->label())->toBe('Defect')
        ->and(static fn (): Tier => Tier::parse('urgent'))
        ->toThrow(InvalidArgumentException::class, 'Unknown tier [urgent]. Expected one of: defect, maintainability, advisory.');
});

it('reads a rule\'s tier from its configuration, and falls back to its category', function (): void {
    $configuration = Configuration::fromArray(['rules' => [
        'SL109' => ['tier' => 'advisory'],
        'SL101' => ['tier' => ''],
    ]], '/tmp');

    expect($configuration->tierFor('SL109', Category::Readability))->toBe(Tier::Advisory)
        ->and($configuration->tierFor('SL101', Category::Complexity))->toBe(Tier::Maintainability)
        ->and($configuration->tierFor('SL107', Category::ErrorHandling))->toBe(Tier::Defect);
});

it('maps findings by category when it has no configuration to read', function (): void {
    $configured = new TierMap(Configuration::fromArray(['rules' => ['SL101' => ['tier' => 'defect']]], '/tmp'));

    expect((new TierMap)->for(finding()))->toBe(Tier::Maintainability)
        ->and($configured->for(finding()))->toBe(Tier::Defect);
});
