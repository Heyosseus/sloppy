<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\RuleRegistry;
use Heyosseus\Sloppy\Tests\Support\RuleTester;

/**
 * The false-positive gate.
 *
 * `tests/Fixtures/Good` is deliberately ordinary Laravel code: a thin
 * controller, a coordinating action, a model with many small members, and a
 * client whose job is to make HTTP calls. A static analyser that complains
 * about code like this is worse than no analyser, so every rule must stay
 * silent here. If a change to any rule breaks this test, the rule is wrong.
 */
it('reports nothing at all on ordinary Laravel code', function (): void {
    $result = RuleTester::runAll(RuleTester::fixtureDirectory('Good'));

    expect($result->findings)->toBe([], sprintf(
        "Expected no findings on clean fixtures, got:\n%s",
        implode("\n", array_map(
            static fn (Finding $f): string => sprintf('%s %s %s — %s', $f->ruleId, $f->severity->value, (string) $f->location, $f->message),
            $result->findings,
        )),
    ))
        ->and($result->score->value)->toBe(100)
        ->and($result->errors)->toBe([]);
});

it('recognises the sloppy fixtures without crashing on any rule', function (): void {
    $result = RuleTester::runAll(RuleTester::fixtureDirectory('Sloppy'));

    expect($result->errors)->toBe([])
        ->and($result->count())->toBeGreaterThan(20)
        ->and($result->score->value)->toBeLessThan(60);
});

it('exercises all but two rules on the sloppy fixtures', function (): void {
    $result = RuleTester::runAll(RuleTester::fixtureDirectory('Sloppy'));
    $fired = array_values(array_unique(ruleIds($result->findings)));
    sort($fired);

    // SL102 needs a genuinely huge class and SL207 a service with eight
    // collaborators; both are covered by their own unit tests with generated
    // sources rather than by hundreds of lines of fixture.
    expect(array_values(array_diff(RuleRegistry::withDefaults()->ids(), $fired)))
        ->toBe(['SL102', 'SL207']);
});

it('finds the same things every time it runs', function (): void {
    $files = RuleTester::fixtureDirectory('Sloppy');

    $first = RuleTester::runAll($files);
    $second = RuleTester::runAll($files);

    expect(array_map(static fn (Finding $f): string => $f->identity(), $first->findings))
        ->toBe(array_map(static fn (Finding $f): string => $f->identity(), $second->findings))
        ->and($first->score->value)->toBe($second->score->value);
});

it('gives every finding a message, an explanation and a suggestion', function (): void {
    $result = RuleTester::runAll(RuleTester::fixtureDirectory('Sloppy'));

    foreach ($result->findings as $finding) {
        expect($finding->message)->not->toBe('')
            ->and($finding->explanation)->not->toBe('')
            ->and($finding->suggestion)->not->toBe('')
            ->and($finding->fingerprint)->not->toBe('')
            ->and($finding->confidence)->toBeGreaterThan(0)
            ->and($finding->location->line)->toBeGreaterThan(0);
    }
});

it('never claims certainty it cannot have', function (): void {
    $result = RuleTester::runAll(RuleTester::fixtureDirectory('Sloppy'));

    foreach ($result->findings as $finding) {
        // A heuristic must not report 100%: confidence is the analyser's
        // certainty that the pattern is present, and it is never absolute.
        expect($finding->confidence)->toBeLessThan(100);
    }
});
