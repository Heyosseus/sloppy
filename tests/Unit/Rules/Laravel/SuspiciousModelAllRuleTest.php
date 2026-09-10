<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Laravel\SuspiciousModelAllRule;

/**
 * @param  array<string, mixed>  $options
 */
function modelAll(array $options = []): SuspiciousModelAllRule
{
    return new SuspiciousModelAllRule($options);
}

it('flags a full load that is then walked row by row', function (): void {
    $found = findings(modelAll(), <<<'PHP'
    class Rows
    {
        public function build(): array
        {
            $orders = Order::all();
            $rows = [];

            foreach ($orders as $order) {
                $rows[] = $order->total;
            }

            return $rows;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL210')
        ->and($found[0]->metrics['model'])->toBe('Order')
        ->and($found[0]->metrics['evidence'])->toBe('iterated')
        ->and($found[0]->suggestion)->toContain('chunkById');
});

it('is most certain about a full load inside a loop', function (): void {
    $found = findings(modelAll(), <<<'PHP'
    class Rows
    {
        public function build(array $tenants): array
        {
            $rows = [];

            foreach ($tenants as $tenant) {
                $rows[] = Order::all();
            }

            return $rows;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['evidence'])->toBe('inside-loop')
        ->and($found[0]->confidence)->toBeGreaterThan(80);
});

it('does not flag a bare full load with no contextual signal', function (): void {
    // Might be a lookup table of twelve rows; without evidence, stay quiet.
    expect(findings(modelAll(), <<<'PHP'
    class Rows
    {
        public function all(): Collection
        {
            return Order::all();
        }
    }
    PHP))->toBeEmpty();
});

it('leaves the chained-collection case to SL205', function (): void {
    expect(findings(modelAll(), <<<'PHP'
    class Rows
    {
        public function active(): Collection
        {
            return Order::all()->where('active', true);
        }
    }
    PHP))->toBeEmpty();
});

it('skips models listed as small reference tables', function (): void {
    expect(findings(modelAll(), <<<'PHP'
    class Rows
    {
        public function build(): array
        {
            $countries = Country::all();
            $rows = [];

            foreach ($countries as $country) {
                $rows[] = $country->name;
            }

            return $rows;
        }
    }
    PHP))->toBeEmpty();
});

it('honours a custom ignore list', function (): void {
    $code = <<<'PHP'
    class Rows
    {
        public function build(): array
        {
            $types = OrderType::all();
            $rows = [];

            foreach ($types as $type) {
                $rows[] = $type->label;
            }

            return $rows;
        }
    }
    PHP;

    expect(findings(modelAll(), $code))->toHaveCount(1)
        ->and(findings(modelAll(['ignore_models' => ['OrderType']]), $code))->toBeEmpty();
});

it('does not flag a facade that exposes all()', function (): void {
    expect(findings(modelAll(), <<<'PHP'
    class Rows
    {
        public function build(): array
        {
            $settings = Config::all();
            $rows = [];

            foreach ($settings as $setting) {
                $rows[] = $setting;
            }

            return $rows;
        }
    }
    PHP))->toBeEmpty();
});

it('flags iterating the call directly', function (): void {
    expect(findings(modelAll(), <<<'PHP'
    class Rows
    {
        public function build(): array
        {
            $rows = [];

            foreach (Order::all() as $order) {
                $rows[] = $order->total;
            }

            return $rows;
        }
    }
    PHP))->toHaveCount(1);
});
