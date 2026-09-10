<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Laravel\ExcessiveControllerDependenciesRule;
use Heyosseus\Sloppy\Rules\Laravel\ExcessiveServiceDependenciesRule;

/**
 * A constructor with the requested number of injected collaborators.
 */
function classWithDependencies(string $declaration, int $count): string
{
    $params = implode("\n", array_map(
        static fn (int $i): string => sprintf('            private Dep%d $dep%d,', $i, $i),
        range(1, $count),
    ));

    return <<<PHP
    $declaration
    {
        public function __construct(
    $params
        ) {}
    }
    PHP;
}

describe('SL206 excessive controller dependencies', function (): void {
    it('flags a controller past the limit', function (): void {
        $found = findings(new ExcessiveControllerDependenciesRule, classWithDependencies('class OrderController extends Controller', 6));

        expect($found)->toHaveCount(1)
            ->and($found[0]->ruleId)->toBe('SL206')
            ->and($found[0]->metrics['dependencies'])->toBe(6)
            ->and($found[0]->message)->toContain('$dep1');
    });

    it('accepts a controller at the limit', function (): void {
        expect(findings(new ExcessiveControllerDependenciesRule, classWithDependencies('class OrderController extends Controller', 4)))->toBeEmpty();
    });

    it('ignores non-controllers', function (): void {
        expect(findings(new ExcessiveControllerDependenciesRule, classWithDependencies('class OrderService', 9)))->toBeEmpty();
    });

    it('ignores scalar constructor arguments', function (): void {
        expect(findings(new ExcessiveControllerDependenciesRule, <<<'PHP'
        class ReportController extends Controller
        {
            public function __construct(
                private string $currency,
                private int $precision,
                private array $columns,
                private bool $verbose,
                private float $rate,
                private Reports $reports,
            ) {}
        }
        PHP))->toBeEmpty();
    });

    it('respects a configured limit', function (): void {
        expect(findings(new ExcessiveControllerDependenciesRule(['max_dependencies' => 10]), classWithDependencies('class OrderController extends Controller', 8)))->toBeEmpty();
    });
});

describe('SL207 excessive service dependencies', function (): void {
    it('flags a service past its more generous limit', function (): void {
        $found = findings(new ExcessiveServiceDependenciesRule, classWithDependencies('class OrderService', 9));

        expect($found)->toHaveCount(1)
            ->and($found[0]->ruleId)->toBe('SL207')
            ->and($found[0]->metrics['max'])->toBe(7);
    });

    it('tolerates what would fail a controller', function (): void {
        // Coordinating is a service's job, so the same count that trips SL206
        // is fine here.
        expect(findings(new ExcessiveServiceDependenciesRule, classWithDependencies('class OrderService', 6)))->toBeEmpty()
            ->and(findings(new ExcessiveControllerDependenciesRule, classWithDependencies('class OrderController extends Controller', 6)))->toHaveCount(1);
    });

    it('leaves controllers to SL206', function (): void {
        expect(findings(new ExcessiveServiceDependenciesRule, classWithDependencies('class OrderController extends Controller', 9)))->toBeEmpty();
    });

    it('ignores classes that are neither services nor controllers', function (): void {
        expect(findings(new ExcessiveServiceDependenciesRule, classWithDependencies('class Widget', 12)))->toBeEmpty();
    });
});
