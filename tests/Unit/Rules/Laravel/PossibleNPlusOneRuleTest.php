<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Laravel\PossibleNPlusOneRule;

/**
 * @param  array<string, mixed>  $options
 */
function nPlusOne(array $options = []): PossibleNPlusOneRule
{
    return new PossibleNPlusOneRule($options);
}

it('flags a relationship read inside a loop', function (): void {
    $found = findings(nPlusOne(), <<<'PHP'
    class Rows
    {
        public function build(): array
        {
            $orders = Order::all();
            $rows = [];

            foreach ($orders as $order) {
                $rows[] = $order->customer->name;
            }

            return $rows;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL203')
        ->and($found[0]->metrics['relation'])->toBe('customer')
        ->and($found[0]->metrics['source_is_all'])->toBeTrue()
        ->and($found[0]->suggestion)->toContain("with('customer')");
});

it('flags a relation query terminated inside a loop', function (): void {
    $found = findings(nPlusOne(), <<<'PHP'
    class Rows
    {
        public function build(Collection $orders): array
        {
            $rows = [];

            foreach ($orders as $order) {
                $rows[] = $order->items()->count();
            }

            return $rows;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['relation'])->toBe('items')
        ->and($found[0]->suggestion)->toContain('withCount');
});

it('says the word possible rather than claiming certainty', function (): void {
    $found = findings(nPlusOne(), <<<'PHP'
    class Rows
    {
        public function build(Collection $orders): array
        {
            $rows = [];

            foreach ($orders as $order) {
                $rows[] = $order->customer->name;
            }

            return $rows;
        }
    }
    PHP);

    expect($found[0]->ruleName)->toBe('Possible N+1')
        ->and($found[0]->explanation)->toContain('possible rather than')
        ->and($found[0]->confidence)->toBeLessThan(90);
});

it('stays quiet when the relation is eager loaded', function (): void {
    expect(findings(nPlusOne(), <<<'PHP'
    class Rows
    {
        public function build(): array
        {
            $orders = Order::with('customer')->get();
            $rows = [];

            foreach ($orders as $order) {
                $rows[] = $order->customer->name;
            }

            return $rows;
        }
    }
    PHP))->toBeEmpty();
});

it('understands nested eager loading', function (): void {
    expect(findings(nPlusOne(), <<<'PHP'
    class Rows
    {
        public function build(): array
        {
            $orders = Order::with(['items.product'])->get();
            $rows = [];

            foreach ($orders as $order) {
                $rows[] = $order->items->count();
            }

            return $rows;
        }
    }
    PHP))->toBeEmpty();
});

it('understands eager loading declared with a constraint closure', function (): void {
    expect(findings(nPlusOne(), <<<'PHP'
    class Rows
    {
        public function build(): array
        {
            $orders = Order::with(['customer' => fn ($q) => $q->select('id', 'name')])->get();
            $rows = [];

            foreach ($orders as $order) {
                $rows[] = $order->customer->name;
            }

            return $rows;
        }
    }
    PHP))->toBeEmpty();
});

it('mentions what was eager loaded when a different relation is read', function (): void {
    $found = findings(nPlusOne(), <<<'PHP'
    class Rows
    {
        public function build(): array
        {
            $orders = Order::with('customer')->get();
            $rows = [];

            foreach ($orders as $order) {
                $rows[] = $order->shipment->carrier;
            }

            return $rows;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->message)->toContain('eager loads only customer')
        ->and($found[0]->metrics['eager_loaded'])->toBe('customer');
});

it('does not flag a plain attribute read', function (): void {
    expect(findings(nPlusOne(), <<<'PHP'
    class Rows
    {
        public function build(Collection $orders): array
        {
            $rows = [];

            foreach ($orders as $order) {
                $rows[] = $order->total;
            }

            return $rows;
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag iteration over a literal array', function (): void {
    expect(findings(nPlusOne(), <<<'PHP'
    class Rows
    {
        public function build(): array
        {
            $rows = [];
            $items = [['a' => 1], ['a' => 2]];

            foreach ($items as $item) {
                $rows[] = $item['a'];
            }

            return $rows;
        }
    }
    PHP))->toBeEmpty();
});

it('skips relations listed in ignore_relations', function (): void {
    expect(findings(nPlusOne(), <<<'PHP'
    class Rows
    {
        public function build(Collection $items): array
        {
            $rows = [];

            foreach ($items as $item) {
                $rows[] = $item->pivot->quantity;
            }

            return $rows;
        }
    }
    PHP))->toBeEmpty();
});

it('reports each relation once, not once per access', function (): void {
    $found = findings(nPlusOne(), <<<'PHP'
    class Rows
    {
        public function build(Collection $orders): array
        {
            $rows = [];

            foreach ($orders as $order) {
                $rows[] = $order->customer->name;
                $rows[] = $order->customer->email;
                $rows[] = $order->customer->phone;
            }

            return $rows;
        }
    }
    PHP);

    expect($found)->toHaveCount(1);
});

it('does not read a plain object graph as a relation', function (): void {
    // Nothing here is database-backed: `$param->var->name` walks an AST, and
    // an analyser that called that an N+1 would be useless outside Laravel.
    expect(findings(nPlusOne(), <<<'SNIPPET'
    class Inspector
    {
        public function names(array $params): array
        {
            $names = [];

            foreach ($params as $param) {
                $names[] = $param->var->name;
            }

            return $names;
        }
    }
    SNIPPET))->toBeEmpty();
});

it('still flags an unmistakably Eloquent access on an unknown source', function (): void {
    // `->items()->count()` is a relation query however the collection arrived.
    expect(findings(nPlusOne(), <<<'SNIPPET'
    class Inspector
    {
        public function counts(array $orders): array
        {
            $counts = [];

            foreach ($orders as $order) {
                $counts[] = $order->items()->count();
            }

            return $counts;
        }
    }
    SNIPPET))->toHaveCount(1);
});

it('trusts a collection type hint', function (): void {
    expect(findings(nPlusOne(), <<<'SNIPPET'
    class Rows
    {
        public function build(\Illuminate\Support\Collection $orders): array
        {
            $rows = [];

            foreach ($orders as $order) {
                $rows[] = $order->customer->name;
            }

            return $rows;
        }
    }
    SNIPPET))->toHaveCount(1);
});

it('treats a relation on the model itself as database-backed', function (): void {
    expect(findings(nPlusOne(), <<<'SNIPPET'
    class Order extends Model
    {
        public function skus(): array
        {
            $skus = [];

            foreach ($this->items as $item) {
                $skus[] = $item->product->sku;
            }

            return $skus;
        }
    }
    SNIPPET))->toHaveCount(1);
});
