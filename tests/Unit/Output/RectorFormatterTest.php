<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Integrations\Tooling\RectorRules;
use Heyosseus\Sloppy\Output\RectorFormatter;

it('writes a runnable configuration scoped to the files with fixable findings', function (): void {
    $config = (new RectorFormatter)->format(analysisResult([
        finding(rule: 'SL105', file: 'app/Order.php', fingerprint: 'Order::unused'),
        finding(rule: 'SL105', file: 'app/Pay.php', fingerprint: 'Pay::unused'),
        finding(rule: 'SL101', file: 'app/Huge.php', fingerprint: 'Huge::run'),
    ]));

    expect($config)->toStartWith('<?php')
        ->and($config)->toContain('use Rector\Config\RectorConfig;')
        ->and($config)->toContain("__DIR__.'/app/Order.php',")
        ->and($config)->toContain("__DIR__.'/app/Pay.php',")
        // SL101 has no automated fix, so its file is not handed to Rector.
        ->and($config)->not->toContain('app/Huge.php')
        ->and($config)->toContain('\Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector::class,')
        ->and($config)->toContain('// Fixes 2 of 3 finding(s) in this run.')
        ->and($config)->toContain('// SL101 x1: no automated fix -- splitting a long method is a design decision, not a rewrite.');
});

it('is valid PHP that returns a configured Rector', function (): void {
    $generated = (new RectorFormatter)->format(analysisResult([finding(rule: 'SL105')]));

    // Parsed rather than required: running the file would boot Rector inside
    // this process, and what matters here is that the file it wrote is
    // syntactically sound and says what it means to say.
    $parsed = (new Parser)->parse('rector-sloppy.php', $generated);

    expect($parsed->parseError)->toBeNull()
        ->and($generated)->toContain('return RectorConfig::configure()')
        ->and($generated)->toContain('->withPaths([')
        ->and($generated)->toContain('->withRules([');
});

it('still writes a usable file when nothing can be fixed', function (): void {
    $config = (new RectorFormatter)->format(analysisResult([finding(rule: 'SL104')]));

    expect($config)->toContain('// Fixes 0 of 1 finding(s) in this run.')
        ->and($config)->toContain('->withPaths([')
        ->and($config)->toContain('->withRules([');
});

it('says nothing beyond the count for a rule with no recorded reason', function (): void {
    $config = (new RectorFormatter)->format(analysisResult([finding(rule: 'SL201')]));

    expect($config)->toContain('// SL201 x1: no automated fix.');
});

it('lists the findings a person still has to deal with', function (): void {
    $remaining = RectorFormatter::unfixable([
        finding(rule: 'SL105', fingerprint: 'a'),
        finding(rule: 'SL101', fingerprint: 'b'),
    ]);

    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]->ruleId)->toBe('SL101');
});

it('names only Rector rules that exist', function (): void {
    $rules = dirname(__DIR__, 3).'/vendor/rector/rector/rules/';

    foreach (['SL103', 'SL105', 'SL106', 'SL107', 'SL108', 'SL110'] as $id) {
        foreach (RectorRules::for($id) as $class) {
            // Checked as a file rather than with class_exists(): loading a
            // Rector rule into this process pulls in Rector's own bootstrap,
            // and the generated configuration never needs the class loaded
            // here anyway -- only named correctly.
            $path = $rules.str_replace('\\', '/', mb_substr($class, mb_strlen('Rector\\'))).'.php';

            expect(is_file($path))->toBeTrue(sprintf('%s names a Rector rule that does not exist: %s', $id, $class));
        }
    }
})->skip(fn (): bool => ! is_dir(dirname(__DIR__, 3).'/vendor/rector/rector/rules'), 'Rector is not installed.');

it('deduplicates and sorts the rules it collects', function (): void {
    $rules = RectorRules::forAll(['SL108', 'SL110', 'SL999']);

    $sorted = $rules;
    sort($sorted);

    expect($rules)->toBe($sorted)
        ->and($rules)->toBe(array_values(array_unique($rules)))
        ->and(RectorRules::for('sl105'))->toBe(RectorRules::for('SL105'))
        ->and(RectorRules::fixes('SL101'))->toBeFalse()
        ->and(RectorRules::fixes('SL105'))->toBeTrue()
        ->and(RectorRules::reason('SL101'))->toBeString()
        ->and(RectorRules::reason('SL201'))->toBeNull();
});
