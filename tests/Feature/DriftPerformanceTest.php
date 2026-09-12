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

it('searches a realistic 600-body corpus well inside its ceiling', function (): void {
    // A ceiling, not a benchmark. The comparison is quadratic in the number of
    // candidate pairs and quadratic in body length, so this rule is one
    // careless edit away from unusable: dropping the frequency prefilter, or
    // widening the window, turns a fraction of a second into minutes.
    $index = driftPerformanceIndex(600, 120);

    expect($index->blockSignatures())->toHaveCount(600);

    $started = microtime(true);
    $pairs = (new DriftFinder(budget: 28, maxRatio: 0.08, minStatements: 8, maxComparisons: 5000))->pairs($index);
    $elapsed = microtime(true) - $started;

    // Loose enough for a cold shared CI runner, tight enough to fail if the
    // window degenerates to all-pairs or the prefilter stops rejecting.
    expect($elapsed)->toBeLessThan(60.0, sprintf('drift search over 600 bodies took %.2fs', $elapsed))
        // And it must not pass by finding nothing quickly: these bodies differ
        // only in an index that appears in several tokens, so they are genuine
        // near-misses and the search is expected to return some.
        ->and($pairs)->not->toBe([]);
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
