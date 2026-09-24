<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Php\UnusedConstructorDependencyRule;
use Heyosseus\Sloppy\Tests\Support\RuleTester;

function unusedDependency(): UnusedConstructorDependencyRule
{
    return new UnusedConstructorDependencyRule;
}

it('flags a promoted dependency that is never read', function (): void {
    $found = findings(unusedDependency(), <<<'PHP'
    class Checkout
    {
        public function __construct(
            private Payments $payments,
            private Inventory $inventory,
        ) {}

        public function pay(Order $order): void
        {
            $this->payments->charge($order->total);
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL106')
        ->and($found[0]->fingerprint)->toBe('Checkout::$inventory')
        ->and($found[0]->metrics['promoted'])->toBeTrue()
        ->and($found[0]->message)->toContain('$inventory');
});

it('flags a hand-assigned dependency that is never read', function (): void {
    $found = findings(unusedDependency(), <<<'PHP'
    class Checkout
    {
        private Inventory $inventory;

        public function __construct(Inventory $inventory)
        {
            $this->inventory = $inventory;
        }

        public function pay(Order $order): void
        {
            $order->save();
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['promoted'])->toBeFalse();
});

it('does not flag a dependency read in any method', function (): void {
    expect(findings(unusedDependency(), <<<'PHP'
    class Checkout
    {
        public function __construct(private Payments $payments) {}

        public function refund(Order $order): void
        {
            $this->payments->refund($order->charge_id);
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a dependency used only inside string interpolation', function (): void {
    expect(findings(unusedDependency(), <<<'PHP'
    class Greeter
    {
        public function __construct(private Formatter $formatter) {}

        public function greet(string $name): string
        {
            return "hello {$this->formatter->for($name)}";
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a dependency handed to the parent constructor', function (): void {
    expect(findings(unusedDependency(), <<<'PHP'
    class SpecialCheckout extends BaseCheckout
    {
        public function __construct(private Payments $payments)
        {
            parent::__construct($payments);
        }
    }
    PHP))->toBeEmpty();
});

it('ignores scalar and array constructor arguments', function (): void {
    // Configuration, not coupling -- and not this rule's business.
    expect(findings(unusedDependency(), <<<'PHP'
    class Rates
    {
        public function __construct(
            private string $currency,
            private array $overrides,
            private int $precision,
        ) {}

        public function noop(): void {}
    }
    PHP))->toBeEmpty();
});

it('stays quiet when the class reaches members dynamically', function (): void {
    expect(findings(unusedDependency(), <<<'PHP'
    class Container
    {
        public function __construct(private Resolver $resolver) {}

        public function make(string $name): mixed
        {
            return $this->{$name};
        }
    }
    PHP))->toBeEmpty();
});

it('stays quiet about a protected dependency when a subclass exists', function (): void {
    expect(findingsAcross(unusedDependency(), [
        'app/Base.php' => <<<'PHP'
        namespace App;

        class Base
        {
            public function __construct(protected Logger $logger) {}
        }
        PHP,
        'app/Child.php' => <<<'PHP'
        namespace App;

        class Child extends Base
        {
            public function go(): void
            {
                $this->logger->info('from the child');
            }
        }
        PHP,
    ]))->toBeEmpty();
});

it('ignores abstract classes', function (): void {
    expect(findings(unusedDependency(), <<<'PHP'
    abstract class Base
    {
        public function __construct(protected Logger $logger) {}
    }
    PHP))->toBeEmpty();
});

it('ignores a parameter carrying an attribute', function (): void {
    expect(findings(unusedDependency(), <<<'PHP'
    class Reader
    {
        public function __construct(
            #[Inject('reports')] private Connection $connection,
        ) {}

        public function noop(): void {}
    }
    PHP))->toBeEmpty();
});

it('never reports a public property, which any caller may read', function (): void {
    // `$context->index` is read from outside the class, and no rule can see
    // every such call site cheaply.
    expect(findings(unusedDependency(), <<<'SNIPPET'
    final readonly class AnalysisContext
    {
        public function __construct(
            public ParsedFile $file,
            public ProjectIndex $index,
        ) {}
    }
    SNIPPET))->toBeEmpty();
});

it('reports a protected property only when nothing extends the class', function (): void {
    $code = <<<'SNIPPET'
    namespace App;

    class Base
    {
        public function __construct(protected Logger $logger) {}
    }
    SNIPPET;

    expect(findings(unusedDependency(), $code))->toHaveCount(1)
        ->and(findingsAcross(unusedDependency(), [
            'app/Base.php' => $code,
            'app/Child.php' => "namespace App;\n\nclass Child extends Base {}",
        ]))->toBeEmpty();
});

it('does not flag a dependency read by a trait the class uses', function (): void {
    expect(findingsAcross(unusedDependency(), [
        'app/HasIdentity.php' => <<<'PHP'
        namespace App;

        trait HasIdentity
        {
            public function getId(): Identity
            {
                return $this->id;
            }
        }
        PHP,
        'app/Resident.php' => <<<'PHP'
        namespace App;

        final class Resident
        {
            use HasIdentity;

            public function __construct(private Identity $id) {}
        }
        PHP,
    ]))->toBeEmpty();
});

it('does not flag a dependency read by a trait used through another trait', function (): void {
    expect(findingsAcross(unusedDependency(), [
        'app/Traits.php' => <<<'PHP'
        namespace App;

        trait ResolvesPaymentAmount
        {
            public function amount(): int
            {
                return $this->stripe->amount();
            }
        }

        trait BuildsPayments
        {
            use ResolvesPaymentAmount;
        }
        PHP,
        'app/Charge.php' => <<<'PHP'
        namespace App;

        final class Charge
        {
            use BuildsPayments;

            public function __construct(private Stripe $stripe) {}
        }
        PHP,
    ]))->toBeEmpty();
});

it('still flags a dependency when the used trait reads something else', function (): void {
    $found = findingsAcross(unusedDependency(), [
        'app/HasIdentity.php' => <<<'PHP'
        namespace App;

        trait HasIdentity
        {
            public function getId(): Identity
            {
                return $this->id;
            }
        }
        PHP,
        'app/Resident.php' => <<<'PHP'
        namespace App;

        final class Resident
        {
            use HasIdentity;

            public function __construct(
                private Identity $id,
                private Mailer $mailer,
            ) {}
        }
        PHP,
    ]);

    expect(RuleTester::fingerprints($found))->toBe(['Resident::$mailer']);
});

it('stays quiet when a used trait reaches members dynamically', function (): void {
    expect(findingsAcross(unusedDependency(), [
        'app/Magic.php' => <<<'PHP'
        namespace App;

        trait Magic
        {
            public function read(string $name): mixed
            {
                return $this->{$name};
            }
        }
        PHP,
        'app/Resident.php' => <<<'PHP'
        namespace App;

        final class Resident
        {
            use Magic;

            public function __construct(private Mailer $mailer) {}
        }
        PHP,
    ]))->toBeEmpty();
});

it('does not count a trait writing the property as reading it', function (): void {
    $found = findingsAcross(unusedDependency(), [
        'app/ResetsMailer.php' => <<<'PHP'
        namespace App;

        trait ResetsMailer
        {
            public function reset(Mailer $mailer): void
            {
                $this->mailer = $mailer;
            }
        }
        PHP,
        'app/Resident.php' => <<<'PHP'
        namespace App;

        final class Resident
        {
            use ResetsMailer;

            public function __construct(private Mailer $mailer) {}
        }
        PHP,
    ]);

    expect(RuleTester::fingerprints($found))->toBe(['Resident::$mailer']);
});
