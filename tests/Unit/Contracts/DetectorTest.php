<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\RuleRegistry;
use Heyosseus\Sloppy\Contracts\Detector;
use Heyosseus\Sloppy\Contracts\Rule;

it('describes every shipped rule as a detector', function (): void {
    // SL502 is not a Rule but must still carry a name, a category and a
    // severity, because the config list, the baseline, `sloppy rules` and
    // every formatter key off exactly those six methods.
    foreach (RuleRegistry::withDefaults()->rules() as $rule) {
        expect($rule)->toBeInstanceOf(Detector::class);
    }
});

it('leaves analysis on Rule and metadata on Detector', function (): void {
    $detector = new ReflectionClass(Detector::class);
    $rule = new ReflectionClass(Rule::class);

    expect(array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $detector->getMethods(),
    ))->toEqualCanonicalizing(['id', 'name', 'description', 'explanation', 'category', 'severity'])
        ->and($rule->getInterfaceNames())->toContain(Detector::class)
        ->and($rule->getMethod('analyze')->getDeclaringClass()->getName())->toBe(Rule::class);
});
