<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Architecture\EmptyWrapperClassRule;

/**
 * @param  array<string, mixed>  $options
 */
function emptyWrapper(array $options = []): EmptyWrapperClassRule
{
    return new EmptyWrapperClassRule($options);
}

it('flags a class that only forwards', function (): void {
    $found = findings(emptyWrapper(), <<<'PHP'
    class InvoiceManager
    {
        public function __construct(private InvoiceService $service) {}

        public function get(int $id): ?Invoice
        {
            return $this->service->get($id);
        }

        public function store(Invoice $invoice): void
        {
            $this->service->store($invoice);
        }

        public function delete(int $id): void
        {
            $this->service->delete($id);
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL302')
        ->and($found[0]->metrics['target'])->toBe('service')
        ->and($found[0]->metrics['delegating_methods'])->toBe(3)
        ->and($found[0]->message)->toContain('without adding behaviour');
});

it('does not flag a wrapper that adds behaviour', function (): void {
    expect(findings(emptyWrapper(), <<<'PHP'
    class CachingInvoices
    {
        public function __construct(private InvoiceService $service, private Repository $cache) {}

        public function get(int $id): ?Invoice
        {
            return $this->cache->remember('invoice.'.$id, 60, fn () => $this->service->get($id));
        }

        public function store(Invoice $invoice): void
        {
            $this->service->store($invoice);
            $this->cache->forget('invoice.'.$invoice->id);
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag forwarding that transforms its arguments', function (): void {
    expect(findings(emptyWrapper(), <<<'PHP'
    class Adapter
    {
        public function __construct(private Legacy $legacy) {}

        public function find(int $id): mixed
        {
            return $this->legacy->find((string) $id);
        }

        public function all(): mixed
        {
            return $this->legacy->all(true);
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a facade composing several collaborators', function (): void {
    expect(findings(emptyWrapper(), <<<'PHP'
    class Checkout
    {
        public function __construct(private Payments $payments, private Inventory $inventory) {}

        public function charge(int $cents): void
        {
            $this->payments->charge($cents);
        }

        public function reserve(int $sku): void
        {
            $this->inventory->reserve($sku);
        }
    }
    PHP))->toBeEmpty();
});

it('needs more than one method to call something a wrapper', function (): void {
    expect(findings(emptyWrapper(), <<<'PHP'
    class SingleShot
    {
        public function __construct(private Worker $worker) {}

        public function run(int $id): void
        {
            $this->worker->run($id);
        }
    }
    PHP))->toBeEmpty();
});

it('tolerates a configured share of non-delegating methods', function (): void {
    $code = <<<'PHP'
    class Mixed
    {
        public function __construct(private Worker $worker) {}

        public function a(int $id): void
        {
            $this->worker->a($id);
        }

        public function b(int $id): void
        {
            $this->worker->b($id);
        }

        public function c(int $id): void
        {
            $this->worker->c($id);
        }

        public function d(int $id): int
        {
            $value = $this->worker->d($id);

            return $value * 2;
        }
    }
    PHP;

    // Three of four forward: below the default 0.8 ratio, above 0.7.
    expect(findings(emptyWrapper(), $code))->toBeEmpty()
        ->and(findings(emptyWrapper(['min_delegation_ratio' => 0.7]), $code))->toHaveCount(1);
});

it('ignores abstract classes', function (): void {
    expect(findings(emptyWrapper(), <<<'PHP'
    abstract class BaseWrapper
    {
        public function __construct(protected Worker $worker) {}

        public function a(int $id): void
        {
            $this->worker->a($id);
        }

        public function b(int $id): void
        {
            $this->worker->b($id);
        }
    }
    PHP))->toBeEmpty();
});
