<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

beforeEach(function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }
});

function wholeTreeSloppy(string $root): Sloppy
{
    return new Sloppy(Configuration::fromArray(['paths' => ['app'], 'fail_on' => 'high'], $root));
}

function swallowing(string $class, string $namespace = 'App'): string
{
    return <<<PHP
    <?php

    namespace {$namespace};

    class {$class}
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

it('scores the whole tree, not just the changed files, so a diff agrees with a scan', function (): void {
    $repository = TempRepository::create()
        ->write('app/Old.php', swallowing('Old'))
        ->write('app/Touched.php', "<?php\n\nnamespace App;\n\nclass Touched\n{\n}\n")
        ->commit('first');
    $repository->write('app/Touched.php', "<?php\n\nnamespace App;\n\nclass Touched\n{\n    public int \$count = 0;\n}\n");

    $sloppy = wholeTreeSloppy($repository->path);
    $report = $sloppy->diff('HEAD');
    $scan = $sloppy->analyze();

    expect($report->currentScore->value)->toBe($scan->score->value)
        ->and($report->currentScore->value)->toBeLessThan(100)
        ->and($report->baseScore->value)->toBe($report->currentScore->value)
        ->and($report->new)->toBe([]);

    $repository->remove();
});

it('reports the real tree score for a change that touches no PHP', function (): void {
    $repository = TempRepository::create()
        ->write('app/Old.php', swallowing('Old'))
        ->write('README.md', "# Hi\n")
        ->commit('first');
    $repository->write('README.md', "# Hi again\n");

    $sloppy = wholeTreeSloppy($repository->path);
    $report = $sloppy->diff('HEAD');

    expect($report->changedFiles)->toBe([])
        ->and($report->currentScore->value)->toBe($sloppy->analyze()->score->value)
        ->and($report->currentScore->value)->toBeLessThan(100)
        ->and($report->scoreDelta())->toBe(0);

    $repository->remove();
});

it('counts the findings of a deleted file as resolved, and in the base score', function (): void {
    $repository = TempRepository::create()
        ->write('app/Gone.php', swallowing('Gone'))
        ->write('app/Stays.php', "<?php\n\nnamespace App;\n\nclass Stays\n{\n}\n")
        ->commit('first');
    $repository->delete('app/Gone.php');

    $report = wholeTreeSloppy($repository->path)->diff('HEAD');

    expect($report->resolved)->toHaveCount(1)
        ->and($report->resolved[0]->ruleId)->toBe('SL107')
        ->and($report->resolved[0]->location->relativePath)->toBe('app/Gone.php')
        ->and($report->baseScore->value)->toBeLessThan($report->currentScore->value)
        ->and($report->toArray()['changed_files'])->toBe([
            ['path' => 'app/Gone.php', 'status' => 'deleted', 'changed_lines' => 0],
        ]);

    $repository->remove();
});

it('reports nothing new for a file moved with a plain mv', function (): void {
    $repository = TempRepository::create()
        ->write('app/B.php', swallowing('B'))
        ->commit('first');

    $source = (string) file_get_contents($repository->path.'/app/B.php');
    $repository->delete('app/B.php')->write('app/Sub/B.php', $source);

    $report = wholeTreeSloppy($repository->path)->diff('HEAD');

    expect($report->new)->toBe([])
        ->and($report->resolved)->toBe([])
        ->and($report->existing)->toHaveCount(1)
        ->and($report->toArray()['changed_files'][0])->toMatchArray(['path' => 'app/Sub/B.php', 'status' => 'renamed']);

    $repository->remove();
});

it('treats everything as new in a repository with no commits yet', function (): void {
    $repository = TempRepository::create()->write('app/A.php', swallowing('A'));

    $report = wholeTreeSloppy($repository->path)->diff('HEAD');

    expect($report->new)->toHaveCount(1)
        ->and($report->new[0]->ruleId)->toBe('SL107')
        ->and($report->base)->toBe('HEAD')
        ->and($report->baseScore->value)->toBe(100);

    $repository->remove();
});

it('reports the change of a project that is a subdirectory of the repository', function (): void {
    $repository = TempRepository::create()
        ->write('pkg/app/Foo.php', "<?php\n\nnamespace App;\n\nclass Foo\n{\n}\n")
        ->write('pkg/composer.json', '{}')
        ->commit('first');
    $repository->write('pkg/app/Foo.php', swallowing('Foo'));

    $report = wholeTreeSloppy($repository->path.'/pkg')->diff('HEAD');

    expect($report->changedFileCount())->toBe(1)
        ->and($report->new)->toHaveCount(1)
        ->and($report->new[0]->ruleId)->toBe('SL107')
        ->and($report->new[0]->location->relativePath)->toBe('app/Foo.php');

    $repository->remove();
});

it('finds the same new findings without a score, from the changed files alone', function (): void {
    $repository = TempRepository::create()
        ->write('app/Old.php', swallowing('Old'))
        ->write('app/Touched.php', "<?php\n\nnamespace App;\n\nclass Touched\n{\n}\n")
        ->commit('first');
    $repository->write('app/Touched.php', swallowing('Touched'));

    $sloppy = wholeTreeSloppy($repository->path);
    $scored = $sloppy->diff('HEAD');
    $unscored = $sloppy->diff('HEAD', scored: false);
    $identities = static fn (array $findings): array => array_map(static fn ($finding): string => $finding->identity(), $findings);

    expect($unscored->new)->toHaveCount(1)
        ->and($identities($unscored->new))->toBe($identities($scored->new))
        ->and($unscored->changedFiles)->toEqual($scored->changedFiles)
        // Old.php's finding is in the tree's score, not in the changed files'.
        ->and($unscored->currentScore->value)->toBeGreaterThan($scored->currentScore->value);

    $repository->remove();
});

it('reports nothing without a score for a change that touches no PHP', function (): void {
    $repository = TempRepository::create()
        ->write('app/Old.php', swallowing('Old'))
        ->write('README.md', "# Hi\n")
        ->commit('first');
    $repository->write('README.md', "# Hi again\n");

    $report = wholeTreeSloppy($repository->path)->diff('HEAD', scored: false);

    expect($report->changedFiles)->toBe([])
        ->and($report->new)->toBe([])
        ->and($report->errors)->toBe([]);

    $repository->remove();
});
