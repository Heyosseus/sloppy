<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Php\LongParameterListRule;
use Heyosseus\Sloppy\Tests\Support\RuleTester;

/**
 * @param  array<string, mixed>  $options
 */
function longParams(array $options = []): LongParameterListRule
{
    return new LongParameterListRule($options);
}

it('flags a method signature past the limit', function (): void {
    $found = findings(longParams(['max_params' => 3]), <<<'PHP'
    class Pricing
    {
        public function quote(int $a, int $b, int $c, int $d): int
        {
            return $a + $b + $c + $d;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL113')
        ->and($found[0]->metrics['params'])->toBe(4)
        ->and($found[0]->fingerprint)->toBe('Pricing::quote');
});

it('accepts a signature up to the limit', function (): void {
    expect(findings(longParams(['max_params' => 3]), <<<'PHP'
    class Pricing
    {
        public function quote(int $a, int $b, int $c): int
        {
            return $a + $b + $c;
        }
    }
    PHP))->toBeEmpty();
});

it('does not count constructors by default', function (): void {
    expect(findings(longParams(['max_params' => 3]), <<<'PHP'
    class Money
    {
        public function __construct(
            private int $cents,
            private string $currency,
            private string $source,
            private bool $taxed,
        ) {}
    }
    PHP))->toBeEmpty();
});

it('counts a configured method as ignored too', function (): void {
    expect(findings(longParams(['max_params' => 3, 'ignore_methods' => ['boot']]), <<<'PHP'
    class Pricing
    {
        public function boot(int $a, int $b, int $c, int $d): void {}
    }
    PHP))->toBeEmpty();
});

it('measures functions and property hooks too', function (): void {
    $found = findings(longParams(['max_params' => 2]), <<<'PHP'
    function helper(int $a, int $b, int $c): int
    {
        return $a + $b + $c;
    }

    class Settings
    {
        public int $level {
            set (int $a, int $b, int $c) {
                $this->level = $a + $b + $c;
            }
        }
    }
    PHP);

    expect(RuleTester::fingerprints($found))->toBe(['helper', 'Settings::$level::set']);
});

it('raises confidence with the number of extra parameters', function (): void {
    $shallow = findings(longParams(['max_params' => 2]), <<<'PHP'
    class A
    {
        public function go(int $a, int $b, int $c): void {}
    }
    PHP);

    $deep = findings(longParams(['max_params' => 2]), <<<'PHP'
    class A
    {
        public function go(int $a, int $b, int $c, int $d, int $e, int $f): void {}
    }
    PHP);

    expect($deep[0]->confidence)->toBeGreaterThan($shallow[0]->confidence);
});
