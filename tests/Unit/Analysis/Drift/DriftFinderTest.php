<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Drift\DriftFinder;
use Heyosseus\Sloppy\Analysis\Drift\DriftPair;
use Heyosseus\Sloppy\Analysis\Drift\MaskedDivergence;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;

/**
 * @param  array<string, string>  $files
 */
function driftIndex(array $files): ProjectIndex
{
    $parser = new Parser;
    $parsed = [];

    foreach ($files as $path => $source) {
        $parsed[] = $parser->parse($path, "<?php\n\n".$source);
    }

    return ProjectIndex::build($parsed);
}

/**
 * A corpus of same-length near-clones: every body has the identical token
 * count, so the budget window admits every pair and only the comparison cap
 * can bound the search -- this is the shape of corpus the brief measured
 * 179,700 comparisons against. Each body differs from the others by exactly
 * one renamed method call, which is one token substitution and so a distance
 * of 1, well inside any budget or ratio used below.
 *
 * @return array<string, string>
 */
function driftNearClones(int $count): array
{
    $files = [];

    for ($i = 0; $i < $count; $i++) {
        $files["app/Clone{$i}.php"] = sprintf(
            'class Clone%1$d { public function run($order) {'
            .' $gateway = $this->gateway();'
            .' $response = $gateway->pay($order->total, $order->currency);'
            .' $this->log->info(\'processed\', [\'id\' => $order->id]);'
            .' $order->markPaid%1$d($response->reference);'
            .' return $response->reference;'
            .' } }',
            $i,
        );
    }

    return $files;
}

/**
 * @param  list<DriftPair>  $pairs
 * @return list<string>
 */
function driftIdentities(array $pairs): array
{
    return array_map(
        static fn (DriftPair $pair): string => $pair->a->identity().'|'.$pair->b->identity(),
        $pairs,
    );
}

const DRIFT_GUARDED = <<<'PHP'
class Guarded
{
    public function charge($order)
    {
        if ($order->total <= 0) {
            return null;
        }
        $gateway = $this->gateway();
        $response = $gateway->pay($order->total, $order->currency);
        $this->log->info('charged', ['id' => $order->id]);
        $order->markPaid($response->reference);
        return $response->reference;
    }
}
PHP;

const DRIFT_UNGUARDED = <<<'PHP'
class Unguarded
{
    public function charge($order)
    {
        $gateway = $this->gateway();
        $response = $gateway->pay($order->total, $order->currency);
        $this->log->info('charged', ['id' => $order->id]);
        $order->markPaid($response->reference);
        return $response->reference;
    }
}
PHP;

it('pairs a body with the sibling that carries a guard it lacks', function (): void {
    // Measured directly (see task-4-report.md): Guarded is 168 tokens against
    // Unguarded's 141, a length gap of 27 that the guard clause alone accounts
    // for, and the edit distance is exactly 27 too since the guard is a pure
    // insertion. 24 -- the brief's estimate of "roughly 20 tokens" -- rejects
    // this pair on the length gate before a matrix ever runs, so the budget is
    // raised here, in the test only, to fit the measurement.
    $pairs = (new DriftFinder(budget: 28, maxRatio: 0.25, minStatements: 4))->pairs(driftIndex([
        'app/Guarded.php' => DRIFT_GUARDED,
        'app/Unguarded.php' => DRIFT_UNGUARDED,
    ]));

    expect($pairs)->toHaveCount(1)
        ->and($pairs[0]->distance)->toBeGreaterThan(0)
        ->and([$pairs[0]->a->block->className, $pairs[0]->b->block->className])
        ->toContain('Guarded', 'Unguarded');
});

it('names each unordered pair of bodies at most once', function (): void {
    // Three mutually near bodies, so several pairs exist and the assertion has
    // something to bite on. An earlier version of this test used two bodies at
    // a budget that rejected them, and then asserted uniqueness over an empty
    // array -- green without ever comparing anything.
    //
    // Uniqueness is structural: `pairs()` runs `$j` from `$i + 1`, so it visits
    // each unordered pair once. This test is the regression guard on that loop
    // shape, and it fails if `$j` is ever reset to 0.
    $pairs = (new DriftFinder(budget: 28, maxRatio: 0.95, minStatements: 4))->pairs(driftIndex([
        'app/Guarded.php' => DRIFT_GUARDED,
        'app/Unguarded.php' => DRIFT_UNGUARDED,
        'app/Third.php' => str_replace(['Unguarded', 'markPaid'], ['Third', 'markSettled'], DRIFT_UNGUARDED),
    ]));

    $unordered = array_map(static function (DriftPair $pair): string {
        $sides = [$pair->a->identity(), $pair->b->identity()];
        sort($sides);

        return implode('|', $sides);
    }, $pairs);

    expect($pairs)->not->toBe([])
        ->and(count($unordered))->toBeGreaterThan(1)
        ->and($unordered)->toBe(array_values(array_unique($unordered)));
});

it('keeps or drops the same pair according to the ratio alone', function (): void {
    // A discrimination test, not a presence test: the SAME two bodies, the
    // same budget, and only the ratio changed. Asserting that a strict ratio
    // finds nothing would pass even if the pair were rejected for being out of
    // budget, which is the bug this shape of test is for. Budget raised to 28
    // for the same reason as above -- the measured distance is 27.
    $files = [
        'app/Guarded.php' => DRIFT_GUARDED,
        'app/Unguarded.php' => DRIFT_UNGUARDED,
    ];

    $generous = (new DriftFinder(budget: 28, maxRatio: 0.95, minStatements: 4))->pairs(driftIndex($files));
    $strict = (new DriftFinder(budget: 28, maxRatio: 0.0011, minStatements: 4))->pairs(driftIndex($files));

    expect($generous)->toHaveCount(1)
        ->and($strict)->toBe([])
        ->and($generous[0]->divergenceRatio)->toBeGreaterThan(0.0011);
});

it('ignores bodies below the statement floor', function (): void {
    $pairs = (new DriftFinder(budget: 24, maxRatio: 0.5, minStatements: 8))->pairs(driftIndex([
        'app/A.php' => 'class A { public function run($x) { return $x + 1; } }',
        'app/B.php' => 'class B { public function run($x) { return $x - 1; } }',
    ]));

    expect($pairs)->toBe([]);
});

it('finds nothing in a corpus of one body', function (): void {
    // Renamed from 'never pairs a body with itself', which is what it looked
    // like it tested. With a single body the inner loop never runs, so
    // isSameBodyAs() was never reached and the guard below went unexercised --
    // the assertion held for the arithmetic of the loop bounds instead.
    $pairs = (new DriftFinder(budget: 28, maxRatio: 0.5, minStatements: 4))->pairs(driftIndex([
        'app/Guarded.php' => DRIFT_GUARDED,
    ]));

    expect($pairs)->toBe([]);
});

it('never pairs two bodies that are the same body', function (): void {
    // Two classes of the same name in one file is invalid PHP that parses
    // perfectly well, and it is the only way two indexed bodies can share an
    // identity: isSameBodyAs() compares path, class and method name and not the
    // line. The two bodies here differ by a guard, so without that check they
    // would be a near pair and get reported as one body drifting from itself.
    $pairs = (new DriftFinder(budget: 28, maxRatio: 0.95, minStatements: 4))->pairs(driftIndex([
        'app/Twice.php' => <<<'PHP'
        class Twice
        {
            public function charge($order)
            {
                if ($order->total <= 0) {
                    return null;
                }
                $gateway = $this->gateway();
                $response = $gateway->pay($order->total, $order->currency);
                $this->log->info('charged', ['id' => $order->id]);
                $order->markPaid($response->reference);
                return $response->reference;
            }
        }

        class Twice
        {
            public function charge($order)
            {
                $gateway = $this->gateway();
                $response = $gateway->pay($order->total, $order->currency);
                $this->log->info('charged', ['id' => $order->id]);
                $order->markPaid($response->reference);
                return $response->reference;
            }
        }
        PHP,
    ]));

    expect($pairs)->toBe([]);
});

it('counts a body near two others as a family of three', function (): void {
    // If this fails because Guarded is further than the budget from Third (it
    // differs by both the guard and the renamed call), print the two distances,
    // raise THIS test's budget to fit them, and record the measurement in your
    // report. Do not raise the shipped default to make a test pass.
    $finder = new DriftFinder(budget: 32, maxRatio: 0.95, minStatements: 4);
    $index = driftIndex([
        'app/Guarded.php' => DRIFT_GUARDED,
        'app/Unguarded.php' => DRIFT_UNGUARDED,
        'app/Third.php' => str_replace(['Unguarded', 'markPaid'], ['Third', 'markSettled'], DRIFT_UNGUARDED),
    ]);

    $pairs = $finder->pairs($index);
    $guarded = null;

    foreach ($index->blockSignatures() as $signature) {
        if ($signature->block->className === 'Guarded') {
            $guarded = $signature;
        }
    }

    expect($finder->familySize($pairs, $guarded))->toBe(3);
});

it('caps the search and returns fewer pairs than an uncapped run on the same corpus', function (): void {
    // 20 same-length near-clones windowed against each other by budget alone
    // (no token-count gap ever exceeds it) give 190 candidate pairs, every one
    // of which clears the ratio -- an uncapped search finds all of them. This
    // is the shape of corpus the brief measured 179,700 comparisons against;
    // a small cap of 10 must cut it off long before that, and cut off strictly
    // fewer pairs than the uncapped run finds, not merely "some".
    $index = driftIndex(driftNearClones(20));

    $capped = new DriftFinder(budget: 28, maxRatio: 0.95, minStatements: 4, maxComparisons: 10);
    $cappedPairs = $capped->pairs($index);

    $uncapped = new DriftFinder(budget: 28, maxRatio: 0.95, minStatements: 4);
    $uncappedPairs = $uncapped->pairs($index);

    expect($capped->truncated())->toBeTrue()
        ->and($uncapped->truncated())->toBeFalse()
        ->and(count($cappedPairs))->toBeLessThan(count($uncappedPairs));
});

it('does not trip the cap on a corpus small enough to finish naturally', function (): void {
    $finder = new DriftFinder(budget: 28, maxRatio: 0.25, minStatements: 4, maxComparisons: 5000);

    // Never searched yet: nothing to report as truncated.
    expect($finder->truncated())->toBeFalse();

    $pairs = $finder->pairs(driftIndex([
        'app/Guarded.php' => DRIFT_GUARDED,
        'app/Unguarded.php' => DRIFT_UNGUARDED,
    ]));

    expect($finder->truncated())->toBeFalse()
        ->and($pairs)->toHaveCount(1);
});

it('does not leave a stale truncated answer from a previous search', function (): void {
    // One instance, two searches: a corpus that truncates, then one that
    // does not. `truncated()` must describe only the most recent call.
    $finder = new DriftFinder(budget: 28, maxRatio: 0.95, minStatements: 4, maxComparisons: 10);

    $finder->pairs(driftIndex(driftNearClones(20)));
    expect($finder->truncated())->toBeTrue();

    $finder->pairs(driftIndex([
        'app/Guarded.php' => DRIFT_GUARDED,
        'app/Unguarded.php' => DRIFT_UNGUARDED,
    ]));
    expect($finder->truncated())->toBeFalse();
});

it('finds the identical pairs in the identical order on repeated capped searches', function (): void {
    // The corpus is sorted by token count with a total tie-break, and the
    // loops run `$i` ascending with `$j` from `$i + 1`, so the comparison
    // order -- and the exact point the cap cuts it off -- cannot vary between
    // runs. Comparing identity pairs, not object identity, is the point: a
    // fresh ProjectIndex per run still has to land on the same bodies.
    $files = driftNearClones(20);

    $first = (new DriftFinder(budget: 28, maxRatio: 0.95, minStatements: 4, maxComparisons: 10))
        ->pairs(driftIndex($files));
    $second = (new DriftFinder(budget: 28, maxRatio: 0.95, minStatements: 4, maxComparisons: 10))
        ->pairs(driftIndex($files));

    expect($first)->toHaveCount(10)
        ->and(driftIdentities($first))->toBe(driftIdentities($second));
});

it('finds the minority value among bodies that share a hash', function (): void {
    $index = driftIndex([
        'app/One.php' => 'class One { public function pay($d) { $g = new StripeGateway(); return $g->charge($d->total); } }',
        'app/Two.php' => 'class Two { public function pay($d) { $g = new StripeGateway(); return $g->charge($d->total); } }',
        'app/Three.php' => 'class Three { public function pay($d) { $g = new PaypalGateway(); return $g->charge($d->total); } }',
    ]);

    $group = $index->blockSignatures();
    $divergences = MaskedDivergence::inGroup($group);

    expect($divergences)->toHaveCount(1)
        ->and($divergences[0]['majority'])->toBe('class:StripeGateway')
        ->and($divergences[0]['minority'])->toBe('class:PaypalGateway')
        ->and($divergences[0]['at']->block->className)->toBe('Three');
});

it('reports no masked divergence when the group has no majority', function (): void {
    // Two bodies differing in one value is a coin toss about which is wrong.
    $index = driftIndex([
        'app/One.php' => 'class One { public function pay($d) { $g = new StripeGateway(); return $g->charge($d->total); } }',
        'app/Two.php' => 'class Two { public function pay($d) { $g = new PaypalGateway(); return $g->charge($d->total); } }',
    ]);

    expect(MaskedDivergence::inGroup($index->blockSignatures()))->toBe([]);
});

it('does not truncate a corpus the cheap gates can reject', function (): void {
    // The regression guard on WHAT the comparison ceiling counts.
    //
    // 120 exact duplicates of one body. Every one of the 7,140 pairs reaches
    // the cheap gates -- more than the shipped ceiling of 5,000 -- and every
    // one is rejected there on equal hashes, because identical shape is SL104's
    // finding and not SL111's. So no comparison ever runs and nothing should be
    // truncated.
    //
    // A ceiling that counted arrivals at those gates instead of survivors of
    // them truncates here. That is exactly what shipped, and it cut a real
    // 73,737-line project off at 0.22s, silently dropping half its findings,
    // while all 520 tests stayed green because no corpus in the suite was this
    // wide. An earlier draft of this test used bodies that merely looked
    // different -- they differed by 9 to 18 tokens, which is near-identical by
    // this rule's own definition, so they sailed past the frequency gate and
    // the test failed for a reason of its own making.
    $body = <<<'PHP'
        public function handle($input)
        {
            $a = $this->alpha->resolve($input);
            $b = $this->beta->convert($a, 10);
            $c = $this->gamma->merge($b);
            $d = $this->delta->validate($c);
            $e = $this->epsilon->persist($d);
            $f = $this->zeta->notify($e);
            $g = $this->eta->audit($f);
            return $this->theta->finish($g);
        }
        PHP;

    $files = [];

    for ($i = 0; $i < 120; $i++) {
        $files['app/Copy'.$i.'.php'] = 'class Copy'.$i.' { '.$body.' }';
    }

    $index = driftIndex($files);
    $finder = new DriftFinder(budget: 28, maxRatio: 0.08, minStatements: 8, maxComparisons: 5000);
    $pairs = $finder->pairs($index);

    expect($index->blockSignatures())->toHaveCount(120)
        // Exact duplicates are SL104's, so SL111 finds nothing here...
        ->and($pairs)->toBe([])
        // ...and having found nothing, it must not claim it ran out of budget.
        ->and($finder->truncated())->toBeFalse(
            'the ceiling truncated a corpus whose every pair the cheap gates reject, '
            .'which means it is counting candidates rather than comparisons',
        );
});
