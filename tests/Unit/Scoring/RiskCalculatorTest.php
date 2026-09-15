<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Configuration\RiskConfiguration;
use Heyosseus\Sloppy\Coverage\CoverageMap;
use Heyosseus\Sloppy\Scoring\RiskCalculator;

it('computes the documented worked example end to end', function (): void {
    // Measured on a real 1,075-file application: a high-severity finding at
    // 76% confidence, new, inside the changed hunk, in a class with exactly
    // one usage.
    $f = finding(confidence: 76, severity: Severity::High, metrics: ['blast_radius' => 1]);

    $risk = (new RiskCalculator)->for($f, isNew: true, inHunk: true);

    expect(round($risk->value, 2))->toBe(9.89)
        ->and($risk->explain())->toBe(
            '10.0 (high) x 0.76 (confidence) x 1.00 (new) x 1.00 (in hunk) x 1.30 (1 usage) x 1.00 (coverage unknown) = 9.89',
        );
});

it('multiplies novelty by exactly the documented ratio between new and inherited', function (): void {
    $f = finding(confidence: 80, severity: Severity::Medium);
    $calculator = new RiskCalculator;

    $new = $calculator->for($f, isNew: true);
    $inherited = $calculator->for($f, isNew: false);

    expect($new->value / $inherited->value)->toBe(RiskConfiguration::NOVELTY_NEW / RiskConfiguration::NOVELTY_INHERITED)
        ->and($new->noveltyLabel)->toBe('new')
        ->and($inherited->noveltyLabel)->toBe('inherited');
});

it('multiplies proximity by exactly the documented ratio between in-hunk and nearby', function (): void {
    $f = finding(confidence: 80, severity: Severity::Medium);
    $calculator = new RiskCalculator;

    $inHunk = $calculator->for($f, inHunk: true);
    $nearby = $calculator->for($f, inHunk: false);

    expect($inHunk->value / $nearby->value)->toBe(RiskConfiguration::PROXIMITY_IN_HUNK / RiskConfiguration::PROXIMITY_NEARBY)
        ->and($inHunk->proximityLabel)->toBe('in hunk')
        ->and($nearby->proximityLabel)->toBe('nearby');
});

it('treats unknown novelty and unknown proximity as exactly 1.0, labelled as unknown', function (): void {
    $f = finding(confidence: 80, severity: Severity::Medium);

    $risk = (new RiskCalculator)->for($f);

    expect($risk->novelty)->toBe(1.0)
        ->and($risk->noveltyLabel)->toBe('novelty unknown')
        ->and($risk->proximity)->toBe(1.0)
        ->and($risk->proximityLabel)->toBe('whole file');
});

it('ranks descending by risk', function (): void {
    $low = finding(rule: 'SL1', file: 'app/A.php', fingerprint: 'a', confidence: 100, severity: Severity::Low);
    $high = finding(rule: 'SL2', file: 'app/B.php', fingerprint: 'b', confidence: 100, severity: Severity::Critical);
    $medium = finding(rule: 'SL3', file: 'app/C.php', fingerprint: 'c', confidence: 100, severity: Severity::Medium);

    $ranked = (new RiskCalculator)->rank([$low, $high, $medium]);

    expect(array_map(static fn (array $row): string => $row['finding']->ruleId, $ranked))
        ->toBe(['SL2', 'SL3', 'SL1']);
});

it('breaks a tie between equal-risk findings the same way regardless of input order', function (): void {
    $a = finding(rule: 'SL100', file: 'app/A.php', line: 5, fingerprint: 'a', confidence: 100, severity: Severity::Low);
    $b = finding(rule: 'SL200', file: 'app/B.php', line: 5, fingerprint: 'b', confidence: 100, severity: Severity::Low);
    $c = finding(rule: 'SL300', file: 'app/C.php', line: 5, fingerprint: 'c', confidence: 100, severity: Severity::Low);

    $calculator = new RiskCalculator;

    $paths = static fn (array $ranked): array => array_map(
        static fn (array $row): string => $row['finding']->location->relativePath,
        $ranked,
    );

    expect($paths($calculator->rank([$c, $a, $b])))->toBe(['app/A.php', 'app/B.php', 'app/C.php'])
        ->and($paths($calculator->rank([$a, $b, $c])))->toBe(['app/A.php', 'app/B.php', 'app/C.php'])
        ->and($paths($calculator->rank([$b, $c, $a])))->toBe(['app/A.php', 'app/B.php', 'app/C.php']);
});

it('sums risk per file rather than averaging it, so three small findings can outrank one bigger one', function (): void {
    // Three Low findings (weight 1.5 each, full confidence) sum to 4.5.
    $small = [
        finding(rule: 'SL1', file: 'app/Small.php', line: 1, fingerprint: 's1', confidence: 100, severity: Severity::Low),
        finding(rule: 'SL2', file: 'app/Small.php', line: 2, fingerprint: 's2', confidence: 100, severity: Severity::Low),
        finding(rule: 'SL3', file: 'app/Small.php', line: 3, fingerprint: 's3', confidence: 100, severity: Severity::Low),
    ];

    // One Medium finding (weight 4.0, full confidence) alone is 4.0 -- larger
    // per finding, but the smaller file's SUM wins.
    $big = [finding(rule: 'SL4', file: 'app/Big.php', line: 1, fingerprint: 'b1', confidence: 100, severity: Severity::Medium)];

    $totals = (new RiskCalculator)->byFile([...$small, ...$big]);

    expect(array_key_first($totals))->toBe('app/Small.php')
        ->and(round($totals['app/Small.php'], 2))->toBe(4.5)
        ->and(round($totals['app/Big.php'], 2))->toBe(4.0)
        ->and($totals['app/Small.php'])->toBeGreaterThan($totals['app/Big.php']);
});

it('ignores a non-integer blast_radius metric rather than trusting a malformed value', function (): void {
    $f = finding(confidence: 80, severity: Severity::Medium, metrics: ['blast_radius' => 'forty-seven']);

    $risk = (new RiskCalculator)->for($f);

    expect($risk->blastRadius)->toBeNull()
        ->and($risk->reach)->toBe(1.0);
});

it('leaves ranking untouched when there is no coverage report', function (): void {
    // The regression guard on this whole extension. RiskCalculator promises
    // every factor defaults to 1.0 when it cannot be measured; if that ever
    // stops being true, every existing ranking silently reshuffles.
    $findings = [
        finding(rule: 'SL101', file: 'app/A.php', severity: Severity::High),
        finding(rule: 'SL102', file: 'app/B.php', severity: Severity::Low),
        finding(rule: 'SL103', file: 'app/C.php', severity: Severity::Medium),
    ];

    $values = static fn (RiskCalculator $calculator): array => array_map(
        static fn (array $row): string => $row['finding']->ruleId.':'.round($row['risk']->value, 6),
        $calculator->rank($findings),
    );

    expect($values(new RiskCalculator(new RiskConfiguration, CoverageMap::empty())))
        ->toBe($values(new RiskCalculator));
});

it('raises an untested file above an identical tested one', function (): void {
    $calculator = new RiskCalculator(new RiskConfiguration, new CoverageMap([
        'app/Tested.php' => 1.0,
        'app/Untested.php' => 0.0,
    ]));

    $tested = $calculator->for(finding(rule: 'SL101', file: 'app/Tested.php', severity: Severity::High));
    $untested = $calculator->for(finding(rule: 'SL101', file: 'app/Untested.php', severity: Severity::High));

    expect($untested->value)->toBeGreaterThan($tested->value)
        ->and($untested->value / $tested->value)->toBe(1.5)
        ->and($untested->exposureLabel)->toBe('untested')
        ->and($tested->exposureLabel)->toBe('fully covered');
});

it('says so when coverage is unknown', function (): void {
    $risk = (new RiskCalculator)->for(finding(rule: 'SL101', file: 'app/A.php'));

    expect($risk->exposure)->toBe(1.0)
        ->and($risk->exposureLabel)->toBe('coverage unknown')
        ->and($risk->explain())->toContain('coverage unknown');
});

it('labels a partially covered file with its percentage', function (): void {
    $risk = (new RiskCalculator(new RiskConfiguration, new CoverageMap(['app/A.php' => 0.62])))
        ->for(finding(rule: 'SL101', file: 'app/A.php'));

    expect($risk->exposureLabel)->toBe('62% covered')
        ->and($risk->toArray()['exposure'])->toBe(1.19);
});
