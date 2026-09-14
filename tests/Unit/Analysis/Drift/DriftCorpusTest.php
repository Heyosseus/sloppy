<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Drift\DriftCorpus;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;

/**
 * An index over the given sources.
 *
 * @param  array<string, string>  $files
 */
function corpusIndex(array $files): ProjectIndex
{
    $parser = new Parser;
    $parsed = [];

    foreach ($files as $path => $source) {
        $parsed[] = $parser->parse($path, $source);
    }

    return ProjectIndex::build($parsed);
}

/**
 * Two bodies alike but for one guard, in two files.
 *
 * @return array<string, string>
 */
function corpusSiblings(): array
{
    $body = <<<'PHP'
        $order = $this->orders->find($id);
        $amount = $order->total();
        $reference = $order->reference();
        $this->gateway->refund($reference, $amount);
        $order->markRefunded();
        $this->orders->save($order);
    PHP;

    return [
        'app/RefundA.php' => "<?php\nclass RefundA { public function refund(int \$id): void { if (! \$id) { return; }\n{$body} } }",
        'app/RefundB.php' => "<?php\nclass RefundB { public function refund(int \$id): void {\n{$body} } }",
    ];
}

it('searches once per index and hands the same corpus back', function (): void {
    $index = corpusIndex(corpusSiblings());

    $first = DriftCorpus::for($index, budget: 28, maxRatio: 0.5, minStatements: 4, maxComparisons: 5000);
    $second = DriftCorpus::for($index, budget: 28, maxRatio: 0.5, minStatements: 4, maxComparisons: 5000);

    // Identity, not equality: a second search that produced an equal corpus
    // would still be the bug -- a 1,075-file project paid for that search
    // 1,075 times.
    expect($second)->toBe($first);
});

it('searches again when the thresholds differ', function (): void {
    $index = corpusIndex(corpusSiblings());

    $wide = DriftCorpus::for($index, budget: 28, maxRatio: 0.5, minStatements: 4, maxComparisons: 5000);
    $narrow = DriftCorpus::for($index, budget: 28, maxRatio: 0.5, minStatements: 4, maxComparisons: 1);

    expect($narrow)->not->toBe($wide);
});

it('searches again for a different project', function (): void {
    $options = ['budget' => 28, 'maxRatio' => 0.5, 'minStatements' => 4, 'maxComparisons' => 5000];

    $one = DriftCorpus::for(corpusIndex(corpusSiblings()), ...$options);
    $other = DriftCorpus::for(corpusIndex(corpusSiblings()), ...$options);

    expect($other)->not->toBe($one);
});

it('hands each file only the pairs whose subject lives in it', function (): void {
    $corpus = DriftCorpus::for(corpusIndex(corpusSiblings()), budget: 28, maxRatio: 0.5, minStatements: 4, maxComparisons: 5000);

    $a = $corpus->pairsIn('app/RefundA.php');
    $b = $corpus->pairsIn('app/RefundB.php');

    expect(count($a) + count($b))->toBe(1)
        ->and($corpus->pairsIn('app/Absent.php'))->toBe([])
        ->and($corpus->maskedIn('app/Absent.php'))->toBe([]);

    foreach ([...$a, ...$b] as $pair) {
        expect($corpus->familySize($pair->a))->toBe(2);
    }
});

it('reports a body that drifts with nothing as a family of one', function (): void {
    $index = corpusIndex(corpusSiblings());
    $corpus = DriftCorpus::for($index, budget: 1, maxRatio: 0.001, minStatements: 4, maxComparisons: 5000);

    $signature = $index->blockSignatures()[0];

    expect($corpus->familySize($signature))->toBe(1);
});
