<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Php\DeadPrivateMethodRule;

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
