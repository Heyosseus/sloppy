<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Evidence\EvidenceContext;
use Heyosseus\Sloppy\Evidence\WeakenedTestSource;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

/**
 * @return list<Heyosseus\Sloppy\Analysis\Finding>
 */
function weakenedTests(?string $before, ?string $after, string $path = 'tests/Feature/InvoiceTest.php'): array
{
    $repository = TempRepository::create()->write('app/Invoice.php', '<?php class Invoice {}');

    if ($before !== null) {
        $repository->write($path, $before);
    }

    $repository->commit('base');

    $after === null ? $repository->delete($path) : $repository->write($path, $after);

    $findings = iterator_to_array((new WeakenedTestSource)->evidence(new EvidenceContext(
        git: $repository->client(),
        basePath: $repository->path,
        baseRevision: 'HEAD',
        changedFiles: [],
    )), false);

    $repository->remove();

    return $findings;
}

function phpunitTest(string $body, string $extra = ''): string
{
    return <<<PHP
    <?php
    final class InvoiceTest extends TestCase
    {
        public function test_totals(): void
        {
    {$body}
        }
    {$extra}
    }
    PHP;
}

const THREE_ASSERTIONS = <<<'PHP'
        $invoice = Invoice::make(100);
        $this->assertSame(100, $invoice->net());
        $this->assertSame(20, $invoice->tax());
        $this->assertSame(120, $invoice->total());
PHP;

it('flags a test that lost assertions', function (): void {
    $findings = weakenedTests(phpunitTest(THREE_ASSERTIONS), phpunitTest(<<<'PHP'
            $invoice = Invoice::make(100);
            $this->assertSame(100, $invoice->net());
    PHP));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('SL503')
        ->and($findings[0]->severity)->toBe(Severity::High)
        ->and($findings[0]->location->relativePath)->toBe('tests/Feature/InvoiceTest.php')
        ->and($findings[0]->location->line)->toBe(4)
        ->and($findings[0]->message)->toBe('InvoiceTest::test_totals() makes 1 assertion, down from 3.');
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('flags a test that was skipped', function (): void {
    $findings = weakenedTests(
        phpunitTest(THREE_ASSERTIONS),
        phpunitTest("        \$this->markTestSkipped('flaky');\n".THREE_ASSERTIONS),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toBe('InvoiceTest::test_totals() is now skipped.');
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('flags an assertion that cannot fail', function (): void {
    $findings = weakenedTests(phpunitTest(THREE_ASSERTIONS), phpunitTest(<<<'PHP'
            $invoice = Invoice::make(100);
            $this->assertSame(100, $invoice->net());
            $this->assertSame(20, $invoice->tax());
            $this->assertTrue(true);
    PHP));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->metrics['kind'])->toBe('trivial');
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('reports a trivial assertion in a brand new test more quietly', function (): void {
    // A new test that asserts nothing is weak, not weakened -- often a "did
    // not throw" check. Worth saying, but not worth blocking the agent over.
    $findings = weakenedTests(null, <<<'PHP'
    <?php
    it('exports invoices', function () {
        Exporter::run();

        expect(true)->toBeTrue();
    });
    PHP, 'tests/Feature/ExportTest.php');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Medium)
        ->and($findings[0]->confidence)->toBe(75)
        ->and($findings[0]->message)->toBe('The test "it exports invoices" makes an assertion that cannot fail.')
        ->and($findings[0]->suggestion)->toContain('not->toThrow');
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('says nothing when a test was renamed and edited in the same change', function (): void {
    // Found running SL503 over this repository's own history: "ships
    // twenty-five rules" became "ships twenty-six rules", and its assertion
    // changed with it. A new test that asserts as much replaces the old one.
    $findings = weakenedTests(
        phpunitTest(THREE_ASSERTIONS),
        str_replace(['test_totals', '120'], ['test_totals_include_tax', '121'], phpunitTest(THREE_ASSERTIONS)),
    );

    expect($findings)->toBeEmpty();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('still flags a deleted test whose replacement asserts nothing', function (): void {
    $findings = weakenedTests(
        phpunitTest(THREE_ASSERTIONS),
        str_replace('test_totals', 'test_it_runs', phpunitTest('        $this->assertTrue(true);')),
    );

    expect(messages($findings))
        ->toContain('InvoiceTest::test_totals() was deleted.')
        ->toContain('InvoiceTest::test_it_runs() makes an assertion that cannot fail.');
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('reads pest tests, their describe blocks and chained skips', function (): void {
    $findings = weakenedTests(<<<'PHP'
    <?php
    describe('totals', function () {
        it('adds tax', function () {
            expect(Invoice::make(100)->total())->toBe(120)
                ->and(Invoice::make(0)->total())->toBe(0);
        });

        it('rounds', function () {
            expect(Invoice::make(99)->tax())->toBe(20);
        });
    });
    PHP, <<<'PHP'
    <?php
    describe('totals', function () {
        it('adds tax', function () {
            expect(Invoice::make(100)->total())->toBe(120);
        });

        it('rounds', function () {
            expect(Invoice::make(99)->tax())->toBe(20);
        })->skip();
    });
    PHP);

    expect(messages($findings))
        ->toContain('The test "totals > it adds tax" makes 1 assertion, down from 2.')
        ->toContain('The test "totals > it rounds" is now skipped.');
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('flags a deleted test at medium severity and low confidence', function (): void {
    $findings = weakenedTests(
        phpunitTest(THREE_ASSERTIONS, "\n    public function test_discount(): void\n    {\n        \$this->assertSame(90, Invoice::make(100)->discounted(10));\n    }\n"),
        phpunitTest(THREE_ASSERTIONS),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Medium)
        ->and($findings[0]->confidence)->toBe(60)
        ->and($findings[0]->message)->toBe('InvoiceTest::test_discount() was deleted.');
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('says nothing when a test was only renamed', function (): void {
    $findings = weakenedTests(
        phpunitTest(THREE_ASSERTIONS),
        str_replace('test_totals', 'test_it_computes_totals', phpunitTest(THREE_ASSERTIONS)),
    );

    expect($findings)->toBeEmpty();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('says nothing when assertions moved into a helper', function (): void {
    // Extracting assertions is a refactor, not a weakening. Counting the
    // helper's assertions at the call site is what tells the two apart.
    $findings = weakenedTests(phpunitTest(THREE_ASSERTIONS), phpunitTest(
        "        \$this->assertTotals(Invoice::make(100));\n",
        <<<'PHP'

        private function assertTotals(Invoice $invoice): void
        {
            $this->assertSame(100, $invoice->net());
            $this->assertSame(20, $invoice->tax());
            $this->assertSame(120, $invoice->total());
        }
    PHP,
    ));

    expect($findings)->toBeEmpty();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('says nothing when a test gained assertions', function (): void {
    $findings = weakenedTests(phpunitTest(<<<'PHP'
            $this->assertSame(100, Invoice::make(100)->net());
    PHP), phpunitTest(THREE_ASSERTIONS));

    expect($findings)->toBeEmpty();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('says nothing when a whole test file was deleted', function (): void {
    expect(weakenedTests(phpunitTest(THREE_ASSERTIONS), null))->toBeEmpty();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('ignores php files outside the test paths', function (): void {
    $findings = weakenedTests(
        '<?php class Seeder { public function test_run(): void { $this->assertTrue($x); } }',
        '<?php class Seeder { public function test_run(): void { $this->assertTrue(true); } }',
        'database/Seeder.php',
    );

    expect($findings)->toBeEmpty();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('describes itself the way every other detector does', function (): void {
    $source = new WeakenedTestSource;

    expect($source->id())->toBe('SL503')
        ->and($source->name())->toBe('Weakened Test')
        ->and($source->category())->toBe(Category::Suppression)
        ->and($source->severity())->toBe(Severity::High);
});
