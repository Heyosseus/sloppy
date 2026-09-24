<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Rules\Php\GodMethodRule;

/**
 * @param  array<string, mixed>  $options
 */
function godMethod(array $options = []): GodMethodRule
{
    return new GodMethodRule($options);
}

/**
 * A method that is long, branchy and chatty all at once.
 */
function branchyMethod(int $branches): string
{
    $body = '';

    for ($i = 0; $i < $branches; $i++) {
        $body .= <<<PHP
                if (\$input[$i] > $i) {
                    \$total = \$this->pricing->apply(\$total, $i);
                    \$this->logger->info('step $i');
                }

        PHP;
    }

    return <<<PHP
    class Checkout
    {
        public function process(array \$input): int
        {
            \$total = 0;
    $body
            return \$total;
        }
    }
    PHP;
}

it('flags a method that is long, complex and chatty', function (): void {
    $found = findings(godMethod(), branchyMethod(30));

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL101')
        ->and($found[0]->severity)->toBe(Severity::High)
        ->and($found[0]->fingerprint)->toBe('Checkout::process')
        ->and($found[0]->message)->toContain('Checkout::process()')
        ->and($found[0]->metrics['signals'])->toBeGreaterThanOrEqual(2)
        ->and($found[0]->confidence)->toBeGreaterThan(80);
});

it('leaves a short delegating method alone', function (): void {
    expect(findings(godMethod(), <<<'PHP'
    class Checkout
    {
        public function process(array $input): int
        {
            $total = $this->pricing->total($input);

            return $this->rounding->apply($total);
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a method on one signal alone', function (): void {
    // 40 trivial assignments: long-ish, but no branching, few collaborators.
    $body = implode("\n", array_map(
        static fn (int $i): string => sprintf('        $row%d = %d;', $i, $i),
        range(1, 40),
    ));

    expect(findings(godMethod(['max_lines' => 30, 'max_statements' => 100]), <<<PHP
    class Report
    {
        public function build(): array
        {
    $body

            return [];
        }
    }
    PHP))->toBeEmpty();
});

it('flags a single overwhelming signal past double the limit', function (): void {
    $body = implode("\n", array_map(
        static fn (int $i): string => sprintf('        $row%d = %d;', $i, $i),
        range(1, 70),
    ));

    expect(findings(godMethod(['max_lines' => 30]), <<<PHP
    class Report
    {
        public function build(): array
        {
    $body

            return [];
        }
    }
    PHP))->toHaveCount(1);
});

it('respects configured thresholds', function (): void {
    $code = branchyMethod(6);

    expect(findings(godMethod(['max_lines' => 500, 'max_complexity' => 500, 'max_statements' => 500, 'max_calls' => 500, 'max_collaborators' => 500, 'max_nesting' => 500]), $code))->toBeEmpty()
        ->and(findings(godMethod(['max_lines' => 5, 'max_complexity' => 2]), $code))->toHaveCount(1);
});

it('honours a severity override', function (): void {
    $found = findings(godMethod(['severity' => 'low']), branchyMethod(30));

    expect($found[0]->severity)->toBe(Severity::Low);
});

it('ignores abstract and interface methods that have no body', function (): void {
    expect(findings(godMethod(['max_lines' => 1, 'max_complexity' => 1]), <<<'PHP'
    interface Payments
    {
        public function charge(int $cents): bool;
    }

    abstract class BasePayments
    {
        abstract public function refund(string $id): bool;
    }
    PHP))->toBeEmpty();
});

it('does not flag a method that is one long lookup table', function (): void {
    // A country calling-code lookup: 200 arms, every one a literal.
    $arms = implode("\n", array_map(
        static fn (int $i): string => sprintf("                'C%d' => '+%d',", $i, $i),
        range(1, 200),
    ));

    expect(findings(godMethod(), <<<PHP
    class CallingCodes
    {
        public function for(string \$country): ?string
        {
            return match (\$country) {
    $arms
                default => null,
            };
        }
    }
    PHP))->toBeEmpty();
});
