<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Analyzer;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\RuleRegistry;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Rules\BaseRule;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;
use RuntimeException;

/**
 * A rule that explodes, to prove one bad rule cannot take down a run.
 */
final class ExplodingRule extends BaseRule
{
    public function id(): string
    {
        return 'SL900';
    }

    public function name(): string
    {
        return 'Exploding';
    }

    public function description(): string
    {
        return 'Throws.';
    }

    public function category(): Category
    {
        return Category::Architecture;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Info;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        throw new RuntimeException('Something went wrong in this rule.');
    }
}

/**
 * A rule that reports one finding per file, at a configurable confidence.
 */
final class AlwaysFiresRule extends BaseRule
{
    public function id(): string
    {
        return 'SL901';
    }

    public function name(): string
    {
        return 'Always Fires';
    }

    public function description(): string
    {
        return 'Reports once per file.';
    }

    public function category(): Category
    {
        return Category::Architecture;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        yield $this->report(
            context: $context,
            at: $context->locateLine(1),
            message: 'Fired.',
            suggestion: 'Nothing.',
            confidence: $this->intOption('confidence', 90),
            fingerprint: $context->relativePath(),
        );
    }
}

function analyzerWith(RuleRegistry $registry, int $minConfidence = 0): Analyzer
{
    return new Analyzer(new Parser, $registry, new ScoreCalculator, $minConfidence);
}

it('runs every rule over every file', function (): void {
    $result = analyzerWith(RuleRegistry::of([new AlwaysFiresRule]))->analyzeSources([
        'app/A.php' => '<?php class A {}',
        'app/B.php' => '<?php class B {}',
    ]);

    expect($result->count())->toBe(2)
        ->and($result->analyzedFiles)->toBe(['app/A.php', 'app/B.php'])
        ->and($result->errors)->toBe([]);
});

it('records a parse error and analyses the rest', function (): void {
    $result = analyzerWith(RuleRegistry::of([new AlwaysFiresRule]))->analyzeSources([
        'app/Good.php' => '<?php class Good {}',
        'app/Broken.php' => '<?php class Broken { public function',
    ]);

    expect($result->count())->toBe(1)
        ->and($result->analyzedFiles)->toBe(['app/Good.php'])
        ->and($result->errors)->toHaveKey('app/Broken.php');
});

it('records a rule that throws instead of swallowing or aborting', function (): void {
    $result = analyzerWith(RuleRegistry::of([new ExplodingRule, new AlwaysFiresRule]))->analyzeSources([
        'app/A.php' => '<?php class A {}',
    ]);

    // The good rule still ran, and the failure is visible rather than silent.
    expect($result->count())->toBe(1)
        ->and($result->errors)->toHaveKey('SL900 in app/A.php')
        ->and($result->errors['SL900 in app/A.php'])->toBe('Something went wrong in this rule.');
});

it('drops findings below the confidence floor', function (): void {
    $registry = RuleRegistry::of([new AlwaysFiresRule(['confidence' => 40])]);

    expect(analyzerWith($registry)->analyzeSources(['app/A.php' => '<?php class A {}'])->count())->toBe(1)
        ->and(analyzerWith($registry, 60)->analyzeSources(['app/A.php' => '<?php class A {}'])->count())->toBe(0);
});

it('indexes every file but reports only the ones asked for', function (): void {
    // This is what makes cross-file rules work in diff mode.
    $result = analyzerWith(RuleRegistry::of([new AlwaysFiresRule]))->analyzeSources([
        'app/A.php' => '<?php class A {}',
        'app/B.php' => '<?php class B {}',
        'app/C.php' => '<?php class C {}',
    ], ['app/B.php']);

    expect($result->count())->toBe(1)
        ->and($result->analyzedFiles)->toBe(['app/B.php'])
        ->and($result->findings[0]->location->relativePath)->toBe('app/B.php');
});

it('reads files from disk and reports progress', function (): void {
    $root = tempProject([
        'app/A.php' => '<?php class A {}',
        'app/B.php' => '<?php class B {}',
    ]);

    $seen = [];
    $result = analyzerWith(RuleRegistry::of([new AlwaysFiresRule]))->analyze(
        [
            'app/A.php' => $root.'/app/A.php',
            'app/B.php' => $root.'/app/B.php',
        ],
        null,
        static function (string $path) use (&$seen): void {
            $seen[] = $path;
        },
    );

    expect($result->count())->toBe(2)
        ->and($seen)->toBe(['app/A.php', 'app/B.php']);

    removeTree($root);
});

it('records an unreadable file on disk', function (): void {
    $result = analyzerWith(RuleRegistry::of([new AlwaysFiresRule]))->analyze([
        'app/Missing.php' => '/definitely/not/here.php',
    ]);

    expect($result->count())->toBe(0)
        ->and($result->errors)->toHaveKey('app/Missing.php');
});

it('counts analysed lines from significant code only', function (): void {
    $result = analyzerWith(RuleRegistry::of([]))->analyzeSources([
        'app/A.php' => "<?php\n\n// comment\n\nclass A {}\n",
    ]);

    expect($result->analyzedLines)->toBe(2)
        ->and($result->count())->toBe(0)
        ->and($result->score->value)->toBe(100);
});

it('produces identical results for the same input', function (): void {
    $sources = [
        'app/A.php' => (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Sloppy/ReportingService.php'),
        'app/B.php' => (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Sloppy/Order.php'),
    ];

    $analyzer = analyzerWith(RuleRegistry::withDefaults());

    $first = $analyzer->analyzeSources($sources);
    $second = $analyzer->analyzeSources($sources);

    expect(array_map(static fn (Heyosseus\Sloppy\Analysis\Finding $f): string => $f->identity(), $first->findings))
        ->toBe(array_map(static fn (Heyosseus\Sloppy\Analysis\Finding $f): string => $f->identity(), $second->findings))
        ->and($first->score->value)->toBe($second->score->value);
});

it('analyses files that were parsed elsewhere', function (): void {
    // What the watch loop rests on: a tick that re-parses only the file that
    // changed must still produce exactly what a full run produces.
    $sources = [
        'app/A.php' => '<?php class A {}',
        'app/B.php' => '<?php class B {}',
    ];

    $parser = new Parser;
    $parsed = [];

    foreach ($sources as $relative => $source) {
        $parsed[] = $parser->parse($relative, $source);
    }

    $analyzer = analyzerWith(RuleRegistry::of([new AlwaysFiresRule]));

    expect($analyzer->analyzeParsed($parsed)->toArray())
        ->toBe($analyzer->analyzeSources($sources)->toArray());
});

it('keeps the parse errors it was handed', function (): void {
    $result = analyzerWith(RuleRegistry::of([new AlwaysFiresRule]))->analyzeParsed(
        [(new Parser)->parse('app/A.php', '<?php class A {}')],
        ['app/Broken.php' => 'Syntax error.'],
    );

    expect($result->count())->toBe(1)
        ->and($result->errors)->toBe(['app/Broken.php' => 'Syntax error.']);
});

it('indexes every parsed file but reports only the ones asked for', function (): void {
    $parser = new Parser;

    $result = analyzerWith(RuleRegistry::of([new AlwaysFiresRule]))->analyzeParsed(
        [
            $parser->parse('app/A.php', '<?php class A {}'),
            $parser->parse('app/B.php', '<?php class B {}'),
        ],
        [],
        ['app/B.php'],
    );

    expect($result->count())->toBe(1)
        ->and($result->analyzedFiles)->toBe(['app/B.php']);
});
