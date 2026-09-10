<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Php\RedundantConditionRule;

function redundant(): RedundantConditionRule
{
    return new RedundantConditionRule;
}

it('flags a condition re-tested as the first statement inside itself', function (): void {
    $found = findings(redundant(), <<<'PHP'
    class Guard
    {
        public function check(?User $user): bool
        {
            if ($user !== null) {
                if ($user !== null) {
                    return true;
                }
            }

            return false;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL108')
        ->and($found[0]->metrics['match'])->toBe('exact');
});

it('sees through a different spelling of the same test', function (): void {
    $found = findings(redundant(), <<<'PHP'
    class Guard
    {
        public function check(?User $user): bool
        {
            if ($user !== null) {
                if (! is_null($user)) {
                    return true;
                }
            }

            return false;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['match'])->toBe('equivalent')
        ->and($found[0]->confidence)->toBeLessThan(92);
});

it('flags the same condition twice in one if chain', function (): void {
    $found = findings(redundant(), <<<'PHP'
    class Router
    {
        public function route(string $kind): string
        {
            if ($kind === 'a') {
                return 'A';
            } elseif ($kind === 'b') {
                return 'B';
            } elseif ($kind === 'a') {
                return 'never';
            }

            return 'other';
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->message)->toContain('can never run');
});

it('flags a condition duplicated across a boolean operator', function (): void {
    $found = findings(redundant(), <<<'PHP'
    class Guard
    {
        public function check(array $window): bool
        {
            return isset($window['from']) && isset($window['from']);
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['operand'])->toBe("isset(\$window['from'])");
});

it('flags a literal true or false guard', function (): void {
    $found = findings(redundant(), <<<'PHP'
    class Flags
    {
        public function go(): string
        {
            if (true) {
                return 'always';
            }

            return 'never';
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['literal'])->toBe('true');
});

it('does not flag nested conditions that test different things', function (): void {
    expect(findings(redundant(), <<<'PHP'
    class Guard
    {
        public function check(?User $user): bool
        {
            if ($user !== null) {
                if ($user->isActive()) {
                    return true;
                }
            }

            return false;
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a narrowing nested condition on a related subject', function (): void {
    expect(findings(redundant(), <<<'PHP'
    class Guard
    {
        public function check(?Order $order): bool
        {
            if ($order !== null) {
                if ($order->customer !== null) {
                    return true;
                }
            }

            return false;
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag an infinite while loop', function (): void {
    expect(findings(redundant(), <<<'PHP'
    class Worker
    {
        public function loop(): void
        {
            while (true) {
                $this->tick();
            }
        }
    }
    PHP))->toBeEmpty();
});

it('does not treat a method call repeated across an operator as redundant', function (): void {
    // Two calls may return different values, so this is not provably redundant.
    expect(findings(redundant(), <<<'PHP'
    class Guard
    {
        public function check(): bool
        {
            return $this->random() && $this->other();
        }
    }
    PHP))->toBeEmpty();
});
