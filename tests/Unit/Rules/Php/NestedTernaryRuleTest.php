<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
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

it('does not flag a chain of short ternaries, which reads as a list of fallbacks', function (): void {
    expect(findings(nestedTernary(), <<<'PHP'
    class Name
    {
        public function display(?string $nick, ?string $first, ?string $last): string
        {
            return $nick ?: $first ?: $last ?: 'anonymous';
        }
    }
    PHP))->toBeEmpty();
});

it('flags a short ternary that falls back to a full one', function (): void {
    expect(findings(nestedTernary(), <<<'PHP'
    class Name
    {
        public function display(?string $nick, bool $formal): string
        {
            return $nick ?: ($formal ? 'Sir' : 'friend');
        }
    }
    PHP))->toHaveCount(1);
});

it('flags a full ternary with a short one in a branch', function (): void {
    expect(findings(nestedTernary(), <<<'PHP'
    class Name
    {
        public function display(bool $known, ?string $nick): string
        {
            return $known ? ($nick ?: 'friend') : 'stranger';
        }
    }
    PHP))->toHaveCount(1);
});

it('says which part of the ternary the nested one sits in', function (string $expression, string $where): void {
    $found = findings(nestedTernary(), <<<PHP
    class Band
    {
        public function label(bool \$a, bool \$b, bool \$c): string
        {
            return {$expression};
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->message)->toContain($where);
})->with([
    'condition' => ['($a ? $b : $c) ? \'yes\' : \'no\'', 'in the condition'],
    'true branch' => ['$a ? ($b ? \'ab\' : \'a\') : \'none\'', 'in the true branch'],
    'false branch' => ['$a ? \'a\' : ($b ? \'b\' : \'none\')', 'in the false branch'],
]);

it('is surer of a ternary nested in the condition than in a branch', function (): void {
    $condition = findings(nestedTernary(), <<<'PHP'
    class Band
    {
        public function label(bool $a, bool $b, bool $c): string
        {
            return ($a ? $b : $c) ? 'yes' : 'no';
        }
    }
    PHP);
    $branch = findings(nestedTernary(), <<<'PHP'
    class Band
    {
        public function label(bool $a, bool $b): string
        {
            return $a ? 'a' : ($b ? 'b' : 'none');
        }
    }
    PHP);

    expect($condition[0]->confidence)->toBeGreaterThan($branch[0]->confidence);
});

it('records the depth and keeps a two-level pair at low severity', function (): void {
    $found = findings(nestedTernary(), <<<'PHP'
    class Band
    {
        public function label(int $age): string
        {
            return $age < 18 ? 'minor' : ($age < 65 ? 'adult' : 'senior');
        }
    }
    PHP);

    expect($found[0]->metrics)->toBe(['depth' => 2])
        ->and($found[0]->severity)->toBe(Severity::Low);
});

it('raises a chain three levels deep to medium severity', function (): void {
    $found = findings(nestedTernary(), <<<'PHP'
    class Band
    {
        public function label(int $age): string
        {
            return $age < 13 ? 'child' : ($age < 18 ? 'teen' : ($age < 65 ? 'adult' : 'senior'));
        }
    }
    PHP);

    expect($found[0]->metrics)->toBe(['depth' => 3])
        ->and($found[0]->severity)->toBe(Severity::Medium)
        ->and($found[0]->message)->toContain('3 levels deep');
});

it('suggests match on the subject when every condition compares it with a value', function (): void {
    $found = findings(nestedTernary(), <<<'PHP'
    class Badge
    {
        public function colour(string $status): string
        {
            return $status === 'paid' ? 'green' : ($status === 'late' ? 'red' : 'grey');
        }
    }
    PHP);

    expect($found[0]->suggestion)->toContain('match ($status)');
});

it('suggests match (true) when the conditions test different things', function (): void {
    $found = findings(nestedTernary(), <<<'PHP'
    class Badge
    {
        public function colour(string $status, bool $vip): string
        {
            return $vip ? 'gold' : ($status === 'late' ? 'red' : 'grey');
        }
    }
    PHP);

    expect($found[0]->suggestion)->toContain('match (true)')
        ->and($found[0]->suggestion)->not->toContain('match ($status)');
});
