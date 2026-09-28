<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Rules\Php\PlaceholderImplementationRule;

it('flags code elided by an agent edit', function (): void {
    $findings = findings(new PlaceholderImplementationRule, <<<'PHP'
    <?php
    class InvoiceService
    {
        public function send(Invoice $invoice): void
        {
            $this->validate($invoice);

            // ... existing code ...

            $this->mailer->send($invoice);
        }
    }
    PHP);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('SL112')
        ->and($findings[0]->location->line)->toBe(8)
        ->and($findings[0]->severity)->toBe(Severity::High)
        ->and($findings[0]->message)->toContain('... existing code ...');
});

it('flags the other spellings of an elision', function (string $comment): void {
    $findings = findings(new PlaceholderImplementationRule, <<<PHP
    <?php
    class Importer
    {
        public function run(): void
        {
            \$this->open();
            {$comment}
        }
    }
    PHP);

    expect($findings)->toHaveCount(1);
})->with([
    '// ...rest of the method unchanged',
    '// Rest of the implementation',
    '/* ... remaining methods ... */',
    '// Add your logic here',
    '# implementation goes here',
    '// … remaining code',
]);

it('flags a body that only throws "not implemented"', function (): void {
    $findings = findings(new PlaceholderImplementationRule, <<<'PHP'
    <?php
    class StripeGateway implements Gateway
    {
        public function refund(Payment $payment): Refund
        {
            throw new \RuntimeException('Not implemented yet');
        }
    }
    PHP);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toBe('StripeGateway::refund() only throws "Not implemented yet".');
});

it('flags a bare "not implemented", however it is spelled', function (string $message): void {
    $findings = findings(new PlaceholderImplementationRule, <<<PHP
    <?php
    class Exporter
    {
        public function export(): string
        {
            throw new \\LogicException('{$message}');
        }
    }
    PHP);

    expect($findings)->toHaveCount(1);
})->with(['Not implemented', 'Method not implemented.', 'TODO', 'Not yet implemented!', 'stub']);

it('says nothing about a bare // ..., which Laravel writes to mean "intentionally empty"', function (): void {
    // Found scanning the framework: eight of these, in empty catch blocks,
    // hook stubs and constructors, every one on purpose.
    $findings = findings(new PlaceholderImplementationRule, <<<'PHP'
    <?php
    class DatabaseStore
    {
        public function add(string $key): bool
        {
            try {
                return $this->table()->insert(['key' => $key]);
            } catch (QueryException) {
                // ...
            }

            return false;
        }

        protected function beforeRefreshingDatabase()
        {
            // ...
        }
    }
    PHP);

    expect($findings)->toBeEmpty();
});

it('says nothing about a method declined on purpose, with the reason in the message', function (): void {
    // Also from the framework: a decorator that does not support part of the
    // interface it implements, and says who decided.
    $findings = findings(new PlaceholderImplementationRule, <<<'PHP'
    <?php
    class SymfonySessionDecorator implements SessionInterface
    {
        public function getBag(string $name): SessionBagInterface
        {
            throw new BadMethodCallException('Method not implemented by Laravel.');
        }
    }
    PHP);

    expect($findings)->toBeEmpty();
});

it('flags a throw of a NotImplemented exception class', function (): void {
    $findings = findings(new PlaceholderImplementationRule, <<<'PHP'
    <?php
    class Exporter
    {
        public function export(): string
        {
            throw new NotImplementedException;
        }
    }
    PHP);

    expect($findings)->toHaveCount(1);
});

it('flags a todo over a trivial return, at medium severity', function (): void {
    $findings = findings(new PlaceholderImplementationRule, <<<'PHP'
    <?php
    class ReportBuilder
    {
        public function rows(): array
        {
            // TODO: query the ledger
            return [];
        }
    }
    PHP);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Medium)
        ->and($findings[0]->message)->toContain('a trivial return');
});

it('flags a plain function too', function (): void {
    $findings = findings(new PlaceholderImplementationRule, <<<'PHP'
    <?php
    function tax_for(int $cents): int
    {
        // FIXME
        return 0;
    }
    PHP);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toStartWith('tax_for()');
});

it('says nothing about an intentional empty default', function (): void {
    // Hook methods and null objects return nothing on purpose. Without a
    // marker saying the work is missing, there is nothing to report.
    $findings = findings(new PlaceholderImplementationRule, <<<'PHP'
    <?php
    class NullCache implements Cache
    {
        public function get(string $key): mixed
        {
            return null;
        }

        public function tags(): array
        {
            // No tags: the null cache stores nothing to tag.
            return [];
        }

        protected function booted(): void {}
    }
    PHP);

    expect($findings)->toBeEmpty();
});

it('says nothing about a deliberate unsupported operation', function (): void {
    $findings = findings(new PlaceholderImplementationRule, <<<'PHP'
    <?php
    final class ImmutableSettings implements ArrayAccess
    {
        public function offsetSet(mixed $offset, mixed $value): void
        {
            throw new \LogicException('Settings are immutable.');
        }
    }
    PHP);

    expect($findings)->toBeEmpty();
});

it('says nothing about a todo in a body that does real work', function (): void {
    $findings = findings(new PlaceholderImplementationRule, <<<'PHP'
    <?php
    class Importer
    {
        public function run(array $rows): int
        {
            // TODO: batch these once the queue supports it
            foreach ($rows as $row) {
                $this->store($row);
            }

            return count($rows);
        }
    }
    PHP);

    expect($findings)->toBeEmpty();
});

it('says nothing about prose that mentions the rest of the code', function (): void {
    $findings = findings(new PlaceholderImplementationRule, <<<'PHP'
    <?php
    class TenantScope
    {
        public function apply(): void
        {
            // The rest of the code assumes a tenant is bound, so fail early here.
            $this->ensureTenant();
            // ...then scope every query to it.
            $this->scope();
        }
    }
    PHP);

    expect($findings)->toBeEmpty();
});

it('says nothing about abstract and interface methods', function (): void {
    $findings = findings(new PlaceholderImplementationRule, <<<'PHP'
    <?php
    interface Gateway
    {
        // TODO: add refunds
        public function charge(): void;
    }

    abstract class Base
    {
        abstract public function handle(): void;
    }
    PHP);

    expect($findings)->toBeEmpty();
});

it('describes itself', function (): void {
    $rule = new PlaceholderImplementationRule;

    expect($rule->id())->toBe('SL112')
        ->and($rule->name())->toBe('Placeholder Implementation')
        ->and($rule->category())->toBe(Category::DeadCode)
        ->and($rule->severity())->toBe(Severity::High);
});
