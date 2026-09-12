<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Drift\BoundedTokenDistance;
use Heyosseus\Sloppy\Ast\BlockSignature;
use Heyosseus\Sloppy\Ast\DuplicateBlock;

/**
 * @param  list<string>  $tokens
 */
function signatureOf(array $tokens, string $name = 'run', string $path = 'app/A.php'): BlockSignature
{
    return BlockSignature::create(
        new DuplicateBlock($path, 'A', $name, 10, 20, count($tokens)),
        hash('sha256', implode('', $tokens)),
        $tokens,
        [],
    );
}

it('returns null for two identical token streams', function (): void {
    $tokens = ['If_', '(', 'V', ')'];

    // Distance zero is SL104's finding, not SL111's. Reporting it here would
    // double-report every exact duplicate in the project.
    expect(BoundedTokenDistance::between(signatureOf($tokens), signatureOf($tokens, 'other'), 24))->toBeNull();
});

it('measures a single substitution as distance one and names where it diverged', function (): void {
    $a = signatureOf(['If_', '(', 'SmallerOrEqual', '(', 'V', ')', ')']);
    $b = signatureOf(['If_', '(', 'Smaller', '(', 'V', ')', ')'], 'other');

    $pair = BoundedTokenDistance::between($a, $b, 24);

    expect($pair)->not->toBeNull()
        ->and($pair->distance)->toBe(1)
        ->and($pair->divergenceIndex)->toBe(2);
});

it('measures an inserted guard as the number of tokens it added', function (): void {
    $without = signatureOf(['Return_', '(', 'V', ')']);
    $with = signatureOf(['If_', '(', 'V', ')', 'Return_', '(', 'V', ')'], 'other');

    $pair = BoundedTokenDistance::between($without, $with, 24);

    expect($pair)->not->toBeNull()
        ->and($pair->distance)->toBe(4);
});

it('returns null once the budget is exceeded', function (): void {
    $a = signatureOf(['A', 'B', 'C', 'D']);
    $b = signatureOf(['W', 'X', 'Y', 'Z'], 'other');

    expect(BoundedTokenDistance::between($a, $b, 3))->toBeNull()
        ->and(BoundedTokenDistance::between($a, $b, 4))->not->toBeNull();
});

it('rejects on length difference without running the comparison', function (): void {
    $short = signatureOf(['A', 'B']);
    $long = signatureOf(array_fill(0, 200, 'A'), 'other');

    expect(BoundedTokenDistance::between($short, $long, 24))->toBeNull();
});

it('never rejects a pair that is genuinely within budget', function (): void {
    // The frequency prefilter is a lower bound, not a heuristic: it must not
    // discard a true near-miss. Same multiset, different order, which is the
    // case a careless bound gets wrong.
    $a = signatureOf(['A', 'B', 'C', 'D', 'E', 'F']);
    $b = signatureOf(['A', 'C', 'B', 'D', 'E', 'F'], 'other');

    $pair = BoundedTokenDistance::between($a, $b, 24);

    expect($pair)->not->toBeNull()
        ->and($pair->distance)->toBeLessThanOrEqual(2);
});

it('reports the divergence ratio against the smaller block', function (): void {
    $a = signatureOf(array_fill(0, 100, 'A'));
    $b = signatureOf([...array_fill(0, 99, 'A'), 'B'], 'other');

    $pair = BoundedTokenDistance::between($a, $b, 24);

    // Compared as a rounded value rather than with toBe(0.01): the ratio is a
    // division and asserting an exact float invites a failure that says
    // nothing about the code.
    expect($pair->distance)->toBe(1)
        ->and(round($pair->divergenceRatio, 6))->toBe(0.01);
});

/**
 * An unbounded Levenshtein, used only as an oracle for the property test
 * below. Quadratic and deliberately naive: correctness is the only thing
 * wanted from it, and the inputs are kept small enough that cost is irrelevant.
 *
 * @param  list<string>  $a
 * @param  list<string>  $b
 */
function oracleDistance(array $a, array $b): int
{
    $rows = count($a);
    $columns = count($b);
    $previous = range(0, $columns);

    for ($i = 1; $i <= $rows; $i++) {
        $current = [$i];

        for ($j = 1; $j <= $columns; $j++) {
            $current[$j] = min(
                $previous[$j] + 1,
                $current[$j - 1] + 1,
                $previous[$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0 : 1),
            );
        }

        $previous = $current;
    }

    return $previous[$columns];
}

it('never rejects or misreports a pair, across randomised edits', function (): void {
    // The frequency prefilter is an exact lower bound, not a heuristic, and the
    // consequence of it being wrong in the rejecting direction is that SL111
    // silently loses findings -- the worst failure mode this rule has. The
    // hand-written cases above each probe one shape; this one probes the
    // boundary itself, by building pairs whose true distance straddles the
    // budget and checking every one against an oracle.
    //
    // The seed is fixed so a failure is reproducible: this is a property test,
    // not a fuzzer, and a test that fails differently on every run is not a
    // test anyone can act on.
    mt_srand(20260912);

    $alphabet = ['If_', '(', ')', 'V', 'L', 'i:id', 'i:total', 'Return_', 'Assign', 'MethodCall', 'n:null', 'C'];
    $budget = 24;
    $near = 0;

    for ($trial = 0; $trial < 400; $trial++) {
        $left = [];

        for ($i = 0, $length = mt_rand(8, 40); $i < $length; $i++) {
            $left[] = $alphabet[mt_rand(0, count($alphabet) - 1)];
        }

        $right = $left;

        // Straddling the budget on purpose: some pairs must be accepted and
        // some rejected, or the test only proves one direction.
        for ($edit = 0, $edits = mt_rand(0, 34); $edit < $edits && $right !== []; $edit++) {
            $at = mt_rand(0, count($right) - 1);
            $token = $alphabet[mt_rand(0, count($alphabet) - 1)];

            match (mt_rand(0, 2)) {
                0 => $right[$at] = $token,
                1 => array_splice($right, $at, 0, [$token]),
                default => array_splice($right, $at, 1),
            };
        }

        $right = array_values($right);

        if ($right === [] || $right === $left) {
            continue;
        }

        $truth = oracleDistance($left, $right);
        $pair = BoundedTokenDistance::between(signatureOf($left), signatureOf($right, 'other'), $budget);

        if ($truth <= $budget) {
            $near++;

            expect($pair)->not->toBeNull("a pair at true distance {$truth} was rejected at budget {$budget}")
                ->and($pair->distance)->toBe($truth);

            continue;
        }

        expect($pair)->toBeNull("a pair at true distance {$truth} was accepted at budget {$budget}");
    }

    // Guards against the whole loop having skipped: a property test that
    // checked nothing would pass silently.
    expect($near)->toBeGreaterThan(100);
});
