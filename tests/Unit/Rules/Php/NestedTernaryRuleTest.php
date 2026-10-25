<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Php\NestedTernaryRule;
use Heyosseus\Sloppy\Tests\Support\RuleTester;

function nestedTernary(): NestedTernaryRule
{
    return new NestedTernaryRule;
}

it('flags a ternary nested in the false branch', function (): void {
    $found = findings(nestedTernary(), <<<'PHP'
    class Band
    {
        public function label(int $age): string
        {
            return $age < 18 ? 'minor' : ($age < 65 ? 'adult' : 'senior');
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL114');
});

it('flags a ternary nested in the true branch', function (): void {
    expect(findings(nestedTernary(), <<<'PHP'
    class Band
    {
        public function label(bool $a, bool $b): string
        {
            return $a ? ($b ? 'ab' : 'a') : 'none';
        }
    }
    PHP))->toHaveCount(1);
});

it('flags a ternary nested in the condition', function (): void {
    expect(findings(nestedTernary(), <<<'PHP'
    class Band
    {
        public function label(bool $a, bool $b, bool $c): string
        {
            return ($a ? $b : $c) ? 'yes' : 'no';
        }
    }
    PHP))->toHaveCount(1);
});

it('reports a three-way chain once, at the outermost ternary', function (): void {
    $found = findings(nestedTernary(), <<<'PHP'
    class Band
    {
        public function label(int $age): string
        {
            return $age < 13 ? 'child' : ($age < 18 ? 'teen' : ($age < 65 ? 'adult' : 'senior'));
        }
    }
    PHP);

    expect($found)->toHaveCount(1);
});

it('does not flag an unnested ternary', function (): void {
    expect(findings(nestedTernary(), <<<'PHP'
    class Band
    {
        public function label(bool $a): string
        {
            return $a ? 'yes' : 'no';
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a ternary nested inside a function call argument', function (): void {
    expect(findings(nestedTernary(), <<<'PHP'
    class Band
    {
        public function label(bool $a, bool $b): string
        {
            return $a ? $this->format($b ? 'x' : 'y') : 'none';
        }
    }
    PHP))->toBeEmpty();
});

it('looks inside functions and property hooks', function (): void {
    $found = findings(nestedTernary(), <<<'PHP'
    function label(bool $a, bool $b): string
    {
        return $a ? ($b ? 'ab' : 'a') : 'none';
    }

    class Account
    {
        public string $status {
            get {
                return $this->active ? ($this->verified ? 'live' : 'pending') : 'closed';
            }
        }
    }
    PHP);

    expect(RuleTester::fingerprints($found))->toHaveCount(2);
});
