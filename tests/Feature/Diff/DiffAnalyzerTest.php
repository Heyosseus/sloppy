<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

beforeEach(function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }
});

function sloppyFor(TempRepository $repository): Sloppy
{
    return new Sloppy(Configuration::fromArray([
        'paths' => ['app'],
        'exclude' => ['vendor'],
        'fail_on' => 'high',
    ], $repository->path));
}

/**
 * A controller action with a swallowed exception, which SL107 reports as HIGH.
 */
function swallowingController(string $class): string
{
    return <<<PHP
    <?php

    namespace App\Http\Controllers;

    class $class
    {
        public function show(int \$id)
        {
            try {
                return \$this->records->find(\$id);
            } catch (\\Throwable \$e) {
                return null;
            }
        }
    }
    PHP;
}

function cleanController(string $class): string
{
    return <<<PHP
    <?php

    namespace App\Http\Controllers;

    class $class
    {
        public function show(int \$id)
        {
            return \$this->records->findOrFail(\$id);
        }
    }
    PHP;
}

it('reports a finding the change introduced as new', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', cleanController('OrderController'))->commit('clean');
    $repository->write('app/OrderController.php', swallowingController('OrderController'));

    $report = sloppyFor($repository)->diff('HEAD');

    expect($report->changedFileCount())->toBe(1)
        ->and($report->new)->toHaveCount(1)
        ->and($report->new[0]->ruleId)->toBe('SL107')
        ->and($report->existing)->toBe([])
        ->and($report->resolved)->toBe([])
        ->and($report->scoreDelta())->toBeLessThan(0);

    $repository->remove();
});

it('reports a finding the change removed as resolved', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', swallowingController('OrderController'))->commit('sloppy');
    $repository->write('app/OrderController.php', cleanController('OrderController'));

    $report = sloppyFor($repository)->diff('HEAD');

    expect($report->new)->toBe([])
        ->and($report->resolved)->toHaveCount(1)
        ->and($report->resolved[0]->ruleId)->toBe('SL107')
        ->and($report->scoreDelta())->toBeGreaterThan(0);

    $repository->remove();
});

it('reports an inherited finding as existing, not new', function (): void {
    // The whole point: debt the change did not cause must not fail its build.
    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', swallowingController('OrderController'))->commit('sloppy');

    // Touch the file without fixing or adding anything.
    $repository->write(
        'app/OrderController.php',
        str_replace('public function show', "// A note.\n    public function show", swallowingController('OrderController')),
    );

    $report = sloppyFor($repository)->diff('HEAD');

    expect($report->new)->toBe([])
        ->and($report->existing)->toHaveCount(1)
        ->and($report->resolved)->toBe([]);

    $repository->remove();
});

it('does not call a finding new just because it moved down the file', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', swallowingController('OrderController'))->commit('sloppy');

    // Push everything down by adding imports at the top.
    $repository->write('app/OrderController.php', str_replace(
        'namespace App\Http\Controllers;',
        "namespace App\\Http\\Controllers;\n\nuse App\\Models\\Order;\nuse Illuminate\\Http\\Request;",
        swallowingController('OrderController'),
    ));

    $report = sloppyFor($repository)->diff('HEAD');

    expect($report->new)->toBe([])
        ->and($report->existing)->toHaveCount(1);

    $repository->remove();
});

it('reports every finding in a brand new file as new', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/Existing.php', cleanController('Existing'))->commit('first');
    $repository->write('app/OrderController.php', swallowingController('OrderController'));

    $report = sloppyFor($repository)->diff('HEAD');

    expect($report->changedFileCount())->toBe(1)
        ->and($report->changedFiles[0]->status)->toBe('untracked')
        ->and($report->new)->toHaveCount(1);

    $repository->remove();
});

it('ignores changes to files outside the configured paths', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/A.php', '<?php class A {}')->commit('first');
    $repository->write('tools/Helper.php', swallowingController('Helper'));

    $report = sloppyFor($repository)->diff('HEAD');

    expect($report->changedFileCount())->toBe(0)
        ->and($report->new)->toBe([]);

    $repository->remove();
});

it('ignores non-php changes', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/A.php', '<?php class A {}')->commit('first');
    $repository->write('app/notes.md', '# notes');

    expect(sloppyFor($repository)->diff('HEAD')->changedFileCount())->toBe(0);

    $repository->remove();
});

it('lets a cross-file rule see files the diff did not touch', function (): void {
    // SL303 needs to know the interface has exactly one implementation, which
    // lives in a file this diff never changed.
    $repository = TempRepository::create();
    $repository->write('app/PdfRenderer.php', <<<'PHP'
    <?php

    namespace App\Rendering;

    class PdfRenderer implements PdfRendererInterface
    {
        public function render(string $html): string
        {
            return base64_encode($html);
        }
    }
    PHP)->commit('implementation only');

    $repository->write('app/PdfRendererInterface.php', <<<'PHP'
    <?php

    namespace App\Rendering;

    interface PdfRendererInterface
    {
        public function render(string $html): string;
    }
    PHP);

    $report = sloppyFor($repository)->diff('HEAD');
    $rules = array_map(static fn (Finding $f): string => $f->ruleId, $report->new);

    expect($rules)->toContain('SL303');

    $repository->remove();
});

it('compares against an older revision', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', cleanController('OrderController'))->commit('first');
    $repository->write('app/OrderController.php', swallowingController('OrderController'))->commit('second');
    $repository->write('app/Untouched.php', cleanController('Untouched'))->commit('third');

    // Two commits back the controller was clean, so the swallow is new there.
    // Against HEAD nothing has changed at all.
    expect(sloppyFor($repository)->diff('HEAD~2')->new)->toHaveCount(1)
        ->and(sloppyFor($repository)->diff('HEAD')->new)->toBe([]);

    $repository->remove();
});

it('filters new findings by threshold', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/A.php', '<?php class A {}')->commit('first');
    $repository->write('app/OrderController.php', swallowingController('OrderController'));

    $report = sloppyFor($repository)->diff('HEAD');

    expect($report->newAtOrAbove(Heyosseus\Sloppy\Analysis\Severity::High))->toHaveCount(1)
        ->and($report->newAtOrAbove(Heyosseus\Sloppy\Analysis\Severity::Critical))->toBe([]);

    $repository->remove();
});

it('exports the documented diff contract', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/A.php', '<?php class A {}')->commit('first');
    $repository->write('app/OrderController.php', swallowingController('OrderController'));

    $array = sloppyFor($repository)->diff('HEAD')->toArray();

    expect($array)->toHaveKeys(['schema', 'tool', 'mode', 'base', 'changed_files', 'score', 'summary', 'new', 'existing', 'resolved', 'errors'])
        ->and($array['mode'])->toBe('diff')
        ->and($array['base'])->toBe('HEAD')
        ->and($array['score'])->toHaveKeys(['base', 'current', 'delta'])
        ->and($array['summary'])->toHaveKeys(['new', 'existing', 'resolved']);

    $repository->remove();
});

it('reports no changes when the tree is clean', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/A.php', '<?php class A {}')->commit('first');

    $report = sloppyFor($repository)->diff('HEAD');

    expect($report)->toBeInstanceOf(DiffReport::class)
        ->and($report->changedFileCount())->toBe(0)
        ->and($report->changedLineCount())->toBe(0)
        ->and($report->scoreDelta())->toBe(0);

    $repository->remove();
});
