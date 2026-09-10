<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Sloppy;

/**
 * Sloppy analysing Sloppy.
 *
 * A code-quality tool that cannot survive its own rules is not worth running,
 * and this is also the only test that exercises the whole package the way a
 * user does: real files on disk, the shipped configuration, every rule.
 */
function selfAnalysis(): AnalysisResult
{
    $root = dirname(__DIR__, 2);

    /** @var array<string, mixed> $config */
    $config = require $root.'/config/sloppy.php';
    $config['paths'] = ['src'];
    $config['enabled'] = true;

    return (new Sloppy(Configuration::fromArray($config, $root)))->analyze();
}

/**
 * Findings Sloppy reports about itself that the maintainers have looked at and
 * decided to keep, with the reason.
 *
 * `NodeHelper` is the shared AST vocabulary every rule is written against. It
 * is genuinely large, and SL102 is right to say so -- but splitting it far
 * enough to clear the rule's thresholds would take five classes, and rules
 * would import three of them to ask three questions. That is the ceremony this
 * package exists to discourage, so the finding stands and this is the record of
 * the decision. Nothing else is accepted.
 *
 * @return list<string>
 */
function acceptedSelfFindings(): array
{
    return ['SL102 src/Ast/NodeHelper.php NodeHelper'];
}

it('reports nothing about itself that has not been signed off', function (): void {
    $result = selfAnalysis();

    $found = array_map(
        static fn (Finding $f): string => sprintf('%s %s %s', $f->ruleId, $f->location->relativePath, $f->fingerprint),
        $result->findings,
    );

    expect($result->errors)->toBe([])
        ->and(array_values(array_diff($found, acceptedSelfFindings())))->toBe([], sprintf(
            "Sloppy found something new about itself:\n%s",
            implode("\n", array_map(
                static fn (Finding $f): string => sprintf('  %s %s — %s', $f->ruleId, (string) $f->location, $f->message),
                $result->findings,
            )),
        ));
});

it('keeps its own score in the Clean band', function (): void {
    $result = selfAnalysis();

    expect($result->score->value)->toBeGreaterThanOrEqual(90)
        ->and($result->score->label())->toBe('Clean');
});

it('analyses its own source without a rule crashing', function (): void {
    // Sloppy's own source is the largest and most varied PHP the test suite
    // parses: enums, readonly classes, promoted properties, first-class
    // callables, generators, match, attributes, heredocs.
    $result = selfAnalysis();

    expect($result->fileCount())->toBeGreaterThan(50)
        ->and($result->analyzedLines)->toBeGreaterThan(3000)
        ->and($result->errors)->toBe([]);
});

it('still accounts for every finding it does report', function (): void {
    $result = selfAnalysis();

    // Whatever is accepted above must actually be present. An entry that no
    // longer matches anything is stale and should be deleted.
    $found = array_map(
        static fn (Finding $f): string => sprintf('%s %s %s', $f->ruleId, $f->location->relativePath, $f->fingerprint),
        $result->findings,
    );

    expect(array_values(array_diff(acceptedSelfFindings(), $found)))->toBe([]);
});
