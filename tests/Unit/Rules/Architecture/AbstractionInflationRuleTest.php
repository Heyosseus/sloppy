<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Architecture\AbstractionInflationRule;

/**
 * @param  array<string, mixed>  $options
 */
function abstractionInflation(array $options = []): AbstractionInflationRule
{
    return new AbstractionInflationRule($options);
}

/**
 * Four layers around one concept, each of them trivial.
 *
 * @return array<string, string>
 */
function inflatedStack(): array
{
    return [
        'app/InvoiceRepositoryInterface.php' => <<<'PHP'
        namespace App\Repositories;

        interface InvoiceRepositoryInterface
        {
            public function find(int $id): ?Invoice;
        }
        PHP,
        'app/InvoiceRepository.php' => <<<'PHP'
        namespace App\Repositories;

        class InvoiceRepository implements InvoiceRepositoryInterface
        {
            public function find(int $id): ?Invoice
            {
                return Invoice::find($id);
            }
        }
        PHP,
        'app/InvoiceService.php' => <<<'PHP'
        namespace App\Services;

        use App\Repositories\InvoiceRepositoryInterface;

        class InvoiceService
        {
            public function __construct(private InvoiceRepositoryInterface $repository) {}

            public function get(int $id): ?Invoice
            {
                return $this->repository->find($id);
            }
        }
        PHP,
        'app/InvoiceManager.php' => <<<'PHP'
        namespace App\Services;

        class InvoiceManager
        {
            public function __construct(private InvoiceService $service) {}

            public function get(int $id): ?Invoice
            {
                return $this->service->get($id);
            }
        }
        PHP,
    ];
}

it('flags layers that are not earning their place', function (): void {
    $found = findingsAcross(abstractionInflation(), inflatedStack());

    expect($found)->not->toBeEmpty()
        ->and($found[0]->ruleId)->toBe('SL301')
        ->and($found[0]->metrics['noun'])->toBe('Invoice')
        ->and($found[0]->metrics['layers'])->toBe(4);
});

it('words itself as advice about current usage, not a verdict', function (): void {
    $found = findingsAcross(abstractionInflation(), inflatedStack());

    expect($found[0]->suggestion)->toContain('may be unnecessary for the current usage')
        ->and($found[0]->explanation)->toContain('not a rule against repositories')
        ->and($found[0]->confidence)->toBeLessThan(80);
});

it('does not flag a concept with only one or two layers', function (): void {
    expect(findingsAcross(abstractionInflation(), [
        'app/Invoice.php' => "namespace App\\Models;\n\nclass Invoice extends Model {}",
        'app/InvoiceService.php' => <<<'PHP'
        namespace App\Services;

        class InvoiceService
        {
            public function get(int $id): ?Invoice
            {
                return Invoice::find($id);
            }
        }
        PHP,
    ]))->toBeEmpty();
});

it('does not flag deep layers that are all doing real work', function (): void {
    // Three layers, but each is substantial and the interface has two
    // implementations -- the indirection is buying something.
    $body = implode("\n", array_map(
        static fn (int $i): string => sprintf('        $step%d = $this->calculate(%d);', $i, $i),
        range(1, 12),
    ));

    expect(findingsAcross(abstractionInflation(), [
        'app/ReportRepositoryInterface.php' => <<<'PHP'
        namespace App\Repositories;

        interface ReportRepositoryInterface
        {
            public function find(int $id): mixed;
        }
        PHP,
        'app/ReportRepository.php' => <<<PHP
        namespace App\Repositories;

        class ReportRepository implements ReportRepositoryInterface
        {
            public function find(int \$id): mixed
            {
        $body

                return \$step1;
            }
        }
        PHP,
        'app/ReportRepositoryCache.php' => <<<PHP
        namespace App\Repositories;

        class ReportRepositoryCache implements ReportRepositoryInterface
        {
            public function find(int \$id): mixed
            {
        $body

                return \$step1;
            }
        }
        PHP,
        'app/ReportService.php' => <<<PHP
        namespace App\Services;

        use App\Repositories\ReportRepositoryInterface;

        class ReportService
        {
            public function __construct(private ReportRepositoryInterface \$repository) {}

            public function get(int \$id): mixed
            {
        $body

                return \$this->repository->find(\$id);
            }
        }
        PHP,
        'app/ReportController.php' => <<<'PHP'
        namespace App\Http\Controllers;

        use App\Services\ReportService;

        class ReportController
        {
            public function __construct(private ReportService $reports) {}

            public function show(int $id): mixed
            {
                return $this->reports->get($id);
            }
        }
        PHP,
        'app/ReportJob.php' => <<<'PHP'
        namespace App\Jobs;

        use App\Services\ReportService;

        class ReportJob
        {
            public function handle(ReportService $reports): void
            {
                $reports->get(1);
            }
        }
        PHP,
    ]))->toBeEmpty();
});

it('respects a configured layer depth', function (): void {
    expect(findingsAcross(abstractionInflation(['min_layers' => 10]), inflatedStack()))->toBeEmpty();
});
