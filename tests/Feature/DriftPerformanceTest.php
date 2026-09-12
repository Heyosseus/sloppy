<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Drift\DriftFinder;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;

/**
 * Bodies of deliberately varied length, the way real code is.
 *
 * Length spread is what keeps the search window small: the window admits only
 * bodies within the budget of each other in token count, so a corpus where
 * every body is the same length puts every pair inside it. Measured on 600
 * bodies at the default budget: one distinct length gives 179,700 comparisons
 * and 318s, forty distinct lengths gives 4,200 and 35s, a hundred and twenty
 * gives 1,200 and 24s. Real code looks like the last of those -- a 1,075-file
 * application yields 11,063 windowed comparisons of which only 348 survive the
 * frequency prefilter.
 *
 * @return array<string, string>
 */
function driftPerformanceCorpus(int $count, int $spread): array
{
    $files = [];

    for ($i = 0; $i < $count; $i++) {
        $padding = '';

        for ($line = 0; $line < $i % $spread; $line++) {
            $padding .= sprintf('        $pad%d = $this->pad%d->value(%d);'.'
', $line, $line, $line);
        }

        $files['app/Generated'.$i.'.php'] = sprintf(<<<'PHP'
        <?php

        class Generated%1$d
        {
            public function handle($input)
            {
                $rows = $this->repository%1$d->forInput($input);
                $mapped = [];
                foreach ($rows as $row) {
                    $mapped[] = $this->mapper%1$d->toArray($row, %1$d);
                }
                $total = count($mapped);
                $label = 'generated-%1$d';
                $this->log->info($label, ['total' => $total]);
        %2$s        return [$label, $total, $mapped];
            }
        }
        PHP, $i, $padding);
    }

    return $files;
}

function driftPerformanceIndex(int $count, int $spread): ProjectIndex
{
    $parser = new Parser;
    $parsed = [];

    foreach (driftPerformanceCorpus($count, $spread) as $path => $source) {
        $parsed[] = $parser->parse($path, $source);
    }

    return ProjectIndex::build($parsed);
}

it('keeps the search linear in a realistic corpus, not quadratic', function (): void {
    // A ceiling, not a benchmark -- and asserted on the comparison COUNT, which
    // is deterministic for a given corpus and identical on every machine.
    //
    // An earlier version asserted wall-clock only: 600 bodies under 60 seconds.
    // That measured 23s on the machine that wrote it and 89s on another, so it
    // failed for reasons that had nothing to do with the code.
    //
    // What this guards, precisely: that the number of comparisons stays
    // proportional to the corpus rather than to its square. The two mechanisms
    // that keep it there are guarded elsewhere, because neither is observable
    // from this corpus -- mutating each one out leaves these counts unchanged:
    //   - the cheap gates' rejections are counted, not skipped:
    //     DriftFinderTest, 'does not truncate a corpus the cheap gates can
    //     reject' (mutation-verified against moving the counter).
    //   - each comparison stays band-limited: the elapsed-time backstop at the
    //     bottom of this test, which is the only thing a count cannot see.
    $index = driftPerformanceIndex(240, 120);

    $finder = new DriftFinder(budget: 28, maxRatio: 0.08, minStatements: 8, maxComparisons: 5000);

    $started = microtime(true);
    $pairs = $finder->pairs($index);
    $elapsed = microtime(true) - $started;

    // 240 bodies is 28,680 possible pairs. Because the corpus spreads its
    // bodies over 120 distinct lengths, the token-count window only admits
    // same-length ones -- measured at 120 comparisons, 0.42% of the total. The
    // bound has room for the fixture to shift without going quadratic.
    expect($finder->comparisons())->toBeLessThan(500, sprintf(
        'the search made %d comparisons out of %d possible pairs, so it is no longer proportional to the corpus',
        $finder->comparisons(),
        240 * 239 / 2,
    ))
        // It must not pass by finding nothing: these bodies differ only in an
        // index appearing in several tokens, so they are genuine near-misses.
        ->and($pairs)->not->toBe([])
        ->and($finder->truncated())->toBeFalse();

    // A loose backstop for the one regression the count cannot see: each
    // comparison is band-limited, so removing the band would leave the count
    // unchanged and make every comparison quadratic in body length instead of
    // linear. Measured at 2.57s, so this is ~23x headroom rather than the 2.5x
    // the old bound had.
    expect($elapsed)->toBeLessThan(60.0, sprintf(
        'the search took %.2fs for %d comparisons, so each one is far more expensive than a band-limited pass',
        $elapsed,
        $finder->comparisons(),
    ));
});

it('stops at max_comparisons instead of running to completion', function (): void {
    // The same-length corpus is the case the cap exists for: every pair lands
    // inside the window and the frequency prefilter cannot reject any of them.
    // Unbounded this is 179,700 comparisons and 318 measured seconds.
    $index = driftPerformanceIndex(200, 1);

    $capped = new DriftFinder(budget: 28, maxRatio: 0.95, minStatements: 8, maxComparisons: 200);

    $started = microtime(true);
    $pairs = $capped->pairs($index);
    $elapsed = microtime(true) - $started;

    expect($capped->truncated())->toBeTrue()
        ->and($pairs)->not->toBe([])
        // 200 comparisons at the measured 1.53 ms each is about a third of a
        // second; anything near this bound means the cap is not being honoured.
        ->and($elapsed)->toBeLessThan(10.0, sprintf('a capped search took %.2fs', $elapsed));
});

it('does not report truncation on a corpus it searched fully', function (): void {
    $finder = new DriftFinder(budget: 28, maxRatio: 0.08, minStatements: 8, maxComparisons: 5000);
    $finder->pairs(driftPerformanceIndex(40, 120));

    expect($finder->truncated())->toBeFalse();
});
