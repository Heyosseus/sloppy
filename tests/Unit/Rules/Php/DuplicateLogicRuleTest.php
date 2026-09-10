<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Php\DuplicateLogicRule;

/**
 * @param  array<string, mixed>  $options
 */
function duplicateLogic(array $options = []): DuplicateLogicRule
{
    return new DuplicateLogicRule($options);
}

const DUPLICATED_PAIR = <<<'PHP'
class Reports
{
    public function orderTotals(int $year): array
    {
        $rows = [];
        $total = 0;
        $records = Order::where('year', $year)->get();

        foreach ($records as $record) {
            $amount = $this->converter->toBase($record->total, $record->currency);
            $total = $total + $amount;
            $rows[] = ['id' => $record->id, 'amount' => $amount];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    public function invoiceTotals(int $year): array
    {
        $items = [];
        $sum = 0;
        $found = Invoice::where('year', $year)->get();

        foreach ($found as $one) {
            $value = $this->converter->toBase($one->total, $one->currency);
            $sum = $sum + $value;
            $items[] = ['id' => $one->id, 'amount' => $value];
        }

        return ['rows' => $items, 'total' => $sum];
    }
}
PHP;

it('flags two methods with the same structure despite different names', function (): void {
    $found = findings(duplicateLogic(), DUPLICATED_PAIR);

    // Both occurrences are reported, so whichever one a diff touches shows up.
    expect($found)->toHaveCount(2)
        ->and(ruleIds($found))->toBe(['SL104', 'SL104'])
        ->and(messages($found))->toContain('Reports::invoiceTotals()')
        ->and(messages($found))->toContain('Reports::orderTotals()')
        ->and($found[0]->metrics['occurrences'])->toBe(2);
});

it('sees through the model a query targets', function (): void {
    // Order::where() and Invoice::where() differ only in subject; the logic
    // wrapped around them is the duplication.
    $found = findings(duplicateLogic(), DUPLICATED_PAIR);

    expect($found[0]->metrics['structural_hash'])->toBe($found[1]->metrics['structural_hash']);
});

it('does not collapse different method calls into a match', function (): void {
    expect(findings(duplicateLogic(['min_statements' => 3]), <<<'PHP'
    class Payments
    {
        public function charge(Order $order): bool
        {
            $reference = $order->reference();
            $result = $this->gateway->charge($order->total, $reference);

            return $result->successful();
        }

        public function refund(Order $order): bool
        {
            $reference = $order->reference();
            $result = $this->gateway->refund($order->total, $reference);

            return $result->successful();
        }
    }
    PHP))->toBeEmpty();
});

it('ignores bodies too short for a structural match to mean anything', function (): void {
    expect(findings(duplicateLogic(), <<<'PHP'
    class Accessors
    {
        public function name(): string
        {
            return $this->attributes['name'];
        }

        public function email(): string
        {
            return $this->attributes['email'];
        }
    }
    PHP))->toBeEmpty();
});

it('matches across files', function (): void {
    $body = <<<'PHP'
        {
            $rows = [];
            $total = 0;
            $records = Thing::query()->get();

            foreach ($records as $record) {
                $amount = $this->converter->toBase($record->total);
                $total = $total + $amount;
                $rows[] = ['id' => $record->id];
            }

            return ['rows' => $rows, 'total' => $total];
        }
    PHP;

    $found = findingsAcross(duplicateLogic(), [
        'app/First.php' => "class First\n{\n    public function build(): array\n".$body."\n}",
        'app/Second.php' => "class Second\n{\n    public function build(): array\n".$body."\n}",
    ]);

    expect($found)->toHaveCount(2)
        ->and(messages($found))->toContain('app/Second.php')
        ->and(messages($found))->toContain('app/First.php');
});

it('skips methods listed in ignore_methods', function (): void {
    expect(findings(duplicateLogic(['ignore_methods' => ['orderTotals', 'invoiceTotals']]), DUPLICATED_PAIR))->toBeEmpty();
});
