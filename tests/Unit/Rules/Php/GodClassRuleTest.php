<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Php\GodClassRule;

/**
 * @param  array<string, mixed>  $options
 */
function godClass(array $options = []): GodClassRule
{
    return new GodClassRule($options);
}

/**
 * Build a class with the requested number of methods, each talking to its own
 * collaborator, so several god-class signals rise together.
 */
function wideClass(string $name, int $methods, string $extends = ''): string
{
    $body = '';

    for ($i = 0; $i < $methods; $i++) {
        $body .= <<<PHP
            public function job$i(int \$id): int
            {
                \$value = \$this->dep{$i}->fetch(\$id);

                return \$this->dep{$i}->apply(\$value);
            }

        PHP;
    }

    $parent = $extends === '' ? '' : ' extends '.$extends;

    return <<<PHP
    class $name$parent
    {
    $body
    }
    PHP;
}

it('flags a class that is large across several dimensions', function (): void {
    $found = findings(godClass(), wideClass('Everything', 30));

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL102')
        ->and($found[0]->fingerprint)->toBe('Everything')
        ->and($found[0]->metrics['methods'])->toBe(30)
        ->and($found[0]->message)->toContain('Everything spans');
});

it('does not flag a class that is merely long', function (): void {
    // One dimension over the limit is not enough on its own.
    expect(findings(godClass(['max_lines' => 10]), wideClass('Modest', 4)))->toBeEmpty();
});

it('leaves an ordinary service class alone', function (): void {
    expect(findings(godClass(), <<<'PHP'
    final class OrderTotals
    {
        public function __construct(private readonly TaxRates $taxes) {}

        public function for(array $lines): int
        {
            return array_sum(array_map(fn (array $line): int => $line['cents'], $lines));
        }

        public function withTax(array $lines, string $region): int
        {
            return $this->taxes->apply($this->for($lines), $region);
        }
    }
    PHP))->toBeEmpty();
});

it('is more lenient with Eloquent models', function (): void {
    // 22 tiny members is over the limit for a service but inside the scaled
    // limit for a model, which is expected to carry many small relations,
    // scopes and accessors. Far enough past the scaled limit and the model is
    // flagged too -- leniency is a wider threshold, not an exemption.
    expect(findings(godClass(), wideClass('BigService', 22)))->toHaveCount(1)
        ->and(findings(godClass(), wideClass('BigModel', 22, 'Model')))->toBeEmpty()
        ->and(findings(godClass(), wideClass('HugeModel', 40, 'Model')))->toHaveCount(1);
});

it('ignores interfaces and enums', function (): void {
    expect(findings(godClass(['max_lines' => 1, 'max_methods' => 1, 'min_signals' => 1]), <<<'PHP'
    interface ManyThings
    {
        public function one(): void;

        public function two(): void;
    }

    enum Status: string
    {
        case Draft = 'draft';
        case Live = 'live';
    }
    PHP))->toBeEmpty();
});

it('counts injected dependencies as a signal', function (): void {
    $found = findings(godClass(['max_dependencies' => 2, 'max_methods' => 2, 'min_signals' => 2]), <<<'PHP'
    class Coordinator
    {
        public function __construct(
            private A $a,
            private B $b,
            private C $c,
            private D $d,
        ) {}

        public function one(): void
        {
            $this->a->go();
        }

        public function two(): void
        {
            $this->b->go();
        }

        public function three(): void
        {
            $this->c->go();
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['dependencies'])->toBe(4);
});

/**
 * A Filament-style component: fluent setters paired with getters, plus a
 * few methods that do real work.
 */
function accessorHeavyClass(string $name, int $pairs, string $extends = ''): string
{
    $body = '';

    for ($i = 0; $i < $pairs; $i++) {
        $body .= <<<PHP
            public function option$i(mixed \$value): static
            {
                \$this->option$i = \$value;

                return \$this;
            }

            public function getOption$i(): mixed
            {
                return \$this->evaluate(\$this->option$i);
            }

            public function isOption{$i}Set(): bool
            {
                return \$this->option$i;
            }

        PHP;
    }

    $parent = $extends === '' ? '' : ' extends '.$extends;

    return <<<PHP
    class $name$parent
    {
        public function toHtml(): string
        {
            return \$this->view->render(\$this->getOption0());
        }

    $body
    }
    PHP;
}

it('does not mention injected dependencies when there are none', function (): void {
    // Framework-resolved classes rarely use constructor injection, so
    // "0 injected dependencies" reads as an accusation with nothing behind it.
    $found = findings(godClass(), wideClass('Everything', 30));

    expect($found[0]->message)->not->toContain('injected dependencies')
        ->and($found[0]->metrics['dependencies'])->toBe(0);
});

it('still mentions injected dependencies when there are some', function (): void {
    $found = findings(godClass(['max_dependencies' => 2, 'max_methods' => 2]), <<<'PHP'
    class Coordinator
    {
        public function __construct(private A $a, private B $b, private C $c) {}

        public function one(): void { $this->a->go(); }

        public function two(): void { $this->b->go(); }

        public function three(): void { $this->c->go(); }
    }
    PHP);

    expect($found[0]->message)->toContain('3 injected dependencies');
});

it('does not count fluent setters and getters as methods', function (): void {
    // 60 accessors a form component exposes by design, and one method with
    // actual behaviour.
    $found = findings(godClass(['min_signals' => 1, 'max_lines' => 5000, 'max_statements' => 5000]), accessorHeavyClass('TextField', 20));

    expect($found)->toBeEmpty();
});

it('reports accessors separately when the class is flagged anyway', function (): void {
    $found = findings(godClass(['min_signals' => 1, 'max_lines' => 10, 'max_collaborators' => 1]), accessorHeavyClass('TextField', 20));

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['methods'])->toBe(1)
        ->and($found[0]->metrics['accessors'])->toBe(60)
        ->and($found[0]->message)->toContain('60 accessors');
});

it('is more lenient with classes extending a framework base', function (): void {
    // The framework decides a Filament resource's or Livewire component's
    // public surface, so they get the same room as a model.
    expect(findings(godClass(), wideClass('OrderResource', 22, '\Filament\Resources\Resource')))->toBeEmpty()
        ->and(findings(godClass(), wideClass('Checkout', 22, '\Livewire\Component')))->toBeEmpty()
        ->and(findings(godClass(), wideClass('HugeResource', 40, '\Filament\Resources\Resource')))->toHaveCount(1);
});

it('follows framework bases through a project base class', function (): void {
    expect(findingsAcross(godClass(), [
        'app/Filament/BaseResource.php' => "namespace App\Filament;\n\nabstract class BaseResource extends \Filament\Resources\Resource {}",
        'app/Filament/OrderResource.php' => "namespace App\Filament;\n\n".wideClass('OrderResource', 22, 'BaseResource'),
    ]))->toBeEmpty();
});

it('honours configured framework bases', function (): void {
    expect(findings(godClass(['framework_bases' => ['App\Screen']]), "namespace App;\n\n".wideClass('Dashboard', 22, 'Screen')))->toBeEmpty()
        ->and(findings(godClass(['framework_bases' => []]), wideClass('OrderResource', 22, '\Filament\Resources\Resource')))->toHaveCount(1);
});

it('asks for more evidence from a class with few collaborators', function (): void {
    // 30 public methods that only talk to the class itself: too many methods
    // and too many public ones, but nothing reaches out, so two size
    // signals are not enough.
    $body = implode("\n", array_map(
        static fn (int $i): string => "    public function hook$i(int \$id): int\n    {\n        return \$this->resolve(\$id) + $i;\n    }\n",
        range(1, 30),
    ));

    expect(findings(godClass(), "class ListOrders\n{\n$body\n}"))->toBeEmpty();
});
