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

    it('does not count data a domain entity is built from', function (): void {
        // Enums, value objects, models, dates and collections are the
        // entity's fields, not collaborators the container resolves.
        expect(findingsAcross(new ExcessiveServiceDependenciesRule, [
            'app/Domain/Types.php' => <<<'PHP'
            namespace App\Domain;

            enum ChargeStatus: string { case Open = 'open'; }

            final readonly class Money
            {
                public function __construct(public int $cents, public string $currency) {}
            }

            final class Period
            {
                public function __construct(
                    public readonly Carbon $from,
                    public readonly Carbon $to,
                ) {}
            }

            class Resident extends \Illuminate\Database\Eloquent\Model {}
            PHP,
            'app/Domain/Charge.php' => <<<'PHP'
            namespace App\Domain;

            use Carbon\CarbonImmutable;
            use Illuminate\Support\Collection;

            final class Charge
            {
                public function __construct(
                    private Money $amount,
                    private Money $balance,
                    private Period $period,
                    private ChargeStatus $status,
                    private Resident $resident,
                    private CarbonImmutable $dueDate,
                    private ?\DateTimeImmutable $paidAt,
                    private Collection $lines,
                    private Ledger $ledger,
                ) {}
            }
            PHP,
        ]))->toBeEmpty();
    });

    it('counts only the collaborators when reporting', function (): void {
        $found = findingsAcross(new ExcessiveServiceDependenciesRule, [
            'app/Status.php' => "namespace App;\n\nenum Status: string { case Open = 'open'; }",
            'app/OrderService.php' => <<<'PHP'
            namespace App;

            class OrderService
            {
                public function __construct(
                    private Dep1 $dep1, private Dep2 $dep2, private Dep3 $dep3,
                    private Dep4 $dep4, private Dep5 $dep5, private Dep6 $dep6,
                    private Dep7 $dep7, private Dep8 $dep8,
                    private Status|string $status, private \Carbon\Carbon $now,
                ) {}
            }
            PHP,
        ]);

        expect($found)->toHaveCount(1)
            ->and($found[0]->metrics['dependencies'])->toBe(8);
    });
});
