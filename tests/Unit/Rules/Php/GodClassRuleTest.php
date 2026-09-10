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
