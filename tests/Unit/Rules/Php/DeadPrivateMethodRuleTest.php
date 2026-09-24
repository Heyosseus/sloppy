<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Php\DeadPrivateMethodRule;
use Heyosseus\Sloppy\Tests\Support\RuleTester;

function deadPrivate(): DeadPrivateMethodRule
{
    return new DeadPrivateMethodRule;
}

it('flags a private method with no caller', function (): void {
    $found = findings(deadPrivate(), <<<'PHP'
    class Invoices
    {
        public function render(Invoice $invoice): string
        {
            return $this->asHtml($invoice);
        }

        private function asHtml(Invoice $invoice): string
        {
            return '<p>'.$invoice->total.'</p>';
        }

        private function asCsvLegacy(Invoice $invoice): string
        {
            return implode(',', [$invoice->id, $invoice->total]);
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL105')
        ->and($found[0]->fingerprint)->toBe('Invoices::asCsvLegacy')
        ->and($found[0]->message)->toContain('asCsvLegacy');
});

it('does not flag a private method called via self or static', function (): void {
    expect(findings(deadPrivate(), <<<'PHP'
    class Tokens
    {
        public function make(): string
        {
            return self::secret().static::salt();
        }

        private static function secret(): string
        {
            return 'a';
        }

        private static function salt(): string
        {
            return 'b';
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a method whose name appears as a string', function (): void {
    // Reached through a callable array or a framework hook, so "unused" would
    // be wrong.
    expect(findings(deadPrivate(), <<<'PHP'
    class Pipeline
    {
        public function run(array $items): array
        {
            return array_map([$this, 'transform'], $items);
        }

        private function transform(int $item): int
        {
            return $item * 2;
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a method passed as a first-class callable', function (): void {
    expect(findings(deadPrivate(), <<<'PHP'
    class Pipeline
    {
        public function run(array $items): array
        {
            return array_map($this->transform(...), $items);
        }

        private function transform(int $item): int
        {
            return $item * 2;
        }
    }
    PHP))->toBeEmpty();
});

it('stays quiet when the class has magic dispatch', function (): void {
    expect(findings(deadPrivate(), <<<'PHP'
    class Magic
    {
        public function __call(string $name, array $arguments): mixed
        {
            return $this->{$name}(...$arguments);
        }

        private function hidden(): string
        {
            return 'x';
        }
    }
    PHP))->toBeEmpty();
});

it('skips magic methods and attributed methods', function (): void {
    expect(findings(deadPrivate(), <<<'PHP_WRAP'
    class Listener
    {
        public function boot(): void
        {
            // nothing
        }
    
        #[Subscribe('order.created')]
        private function onOrderCreated(): void
        {
            // Called by the framework through the attribute.
        }
    
        private function __clone(): void
        {
            // Magic, called by PHP.
        }
    }
    PHP_WRAP))->toBeEmpty();
});

it('leaves traits alone, since the composing class may call them', function (): void {
    expect(findings(deadPrivate(), <<<'PHP'
    trait Sanitises
    {
        private function clean(string $value): string
        {
            return trim($value);
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag protected or public methods', function (): void {
    expect(findings(deadPrivate(), <<<'PHP'
    class Base
    {
        public function unusedPublic(): void {}

        protected function unusedProtected(): void {}
    }
    PHP))->toBeEmpty();
});

it('does not flag a private method called from a trait the class uses', function (): void {
    // The trait declares `abstract private function filter()` and calls it;
    // the class supplies the body. The call site lives in the trait.
    expect(findingsAcross(deadPrivate(), [
        'app/FiltersQueries.php' => <<<'PHP'
        namespace App;

        trait FiltersQueries
        {
            abstract private function filter(Builder $query): Builder;

            public function apply(Builder $query): Builder
            {
                return $this->filter($query);
            }
        }
        PHP,
        'app/ActiveResidents.php' => <<<'PHP'
        namespace App;

        final class ActiveResidents
        {
            use FiltersQueries;

            private function filter(Builder $query): Builder
            {
                return $query->where('active', true);
            }
        }
        PHP,
    ]))->toBeEmpty();
});

it('still flags a private method the used trait does not call', function (): void {
    $found = findingsAcross(deadPrivate(), [
        'app/FiltersQueries.php' => <<<'PHP'
        namespace App;

        trait FiltersQueries
        {
            public function apply(Builder $query): Builder
            {
                return $this->filter($query);
            }
        }
        PHP,
        'app/ActiveResidents.php' => <<<'PHP'
        namespace App;

        final class ActiveResidents
        {
            use FiltersQueries;

            private function filter(Builder $query): Builder
            {
                return $query->where('active', true);
            }

            private function legacy(): void {}
        }
        PHP,
    ]);

    expect(RuleTester::fingerprints($found))->toBe(['ActiveResidents::legacy']);
});

it('does not flag a private method a used trait names as a callback', function (): void {
    expect(findingsAcross(deadPrivate(), [
        'app/SortsRows.php' => <<<'PHP'
        namespace App;

        trait SortsRows
        {
            public function sorted(array $rows): array
            {
                usort($rows, [$this, 'compare']);

                return $rows;
            }
        }
        PHP,
        'app/Report.php' => <<<'PHP'
        namespace App;

        final class Report
        {
            use SortsRows;

            private function compare(array $a, array $b): int
            {
                return $a['total'] <=> $b['total'];
            }
        }
        PHP,
    ]))->toBeEmpty();
});

it('stays quiet when a used trait calls methods dynamically', function (): void {
    expect(findingsAcross(deadPrivate(), [
        'app/Dispatches.php' => <<<'PHP'
        namespace App;

        trait Dispatches
        {
            public function dispatch(string $method): mixed
            {
                return $this->{$method}();
            }
        }
        PHP,
        'app/Report.php' => <<<'PHP'
        namespace App;

        final class Report
        {
            use Dispatches;

            private function monthly(): array
            {
                return [];
            }
        }
        PHP,
    ]))->toBeEmpty();
});
