<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Sloppy;

/**
 * A class that swallows every exception it meets: SL107 on any PHP project.
 */
function swallowingClass(string $name): string
{
    return <<<PHP
    <?php

    declare(strict_types=1);

    namespace App;

    final class {$name}
    {
        public function run(): void
        {
            try {
                \$this->load();
            } catch (\\Throwable \$exception) {
            }
        }

        private function load(): void
        {
            throw new \\RuntimeException('Nothing to load.');
        }
    }
    PHP;
}

/**
 * @return list<string>
 */
function findingPaths(Heyosseus\Sloppy\Analysis\AnalysisResult $result): array
{
    return array_values(array_unique(array_map(
        static fn (Finding $finding): string => $finding->location->relativePath,
        $result->findings,
    )));
}

beforeEach(function (): void {
    $this->project = tempProject([
        'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src/"}}}',
        'sloppy.php' => "<?php return ['paths' => ['src'], 'baseline' => '.sloppy-baseline.json'];",
        'src/Importer.php' => swallowingClass('Importer'),
        'src/Exporter.php' => swallowingClass('Exporter'),
        'tests/ImporterTest.php' => swallowingClass('ImporterTest'),
    ]);
});

afterEach(function (): void {
    removeTree($this->project);
});

it('loads the project configuration the way the binary does', function (): void {
    $sloppy = Sloppy::forProject($this->project);

    expect($sloppy->configuration->paths())->toBe(['src'])
        ->and(array_keys($sloppy->fileMap()))->toBe(['src/Exporter.php', 'src/Importer.php']);
});

it('reads an explicit configuration file when given one', function (): void {
    file_put_contents($this->project.'/custom.php', "<?php return ['paths' => ['tests']];");

    $sloppy = Sloppy::forProject($this->project, $this->project.'/custom.php');

    expect($sloppy->configuration->paths())->toBe(['tests']);
});

it('reports only on the given files that the configuration also covers', function (): void {
    $result = Sloppy::forProject($this->project)->analyzePaths([
        $this->project.'/src/Importer.php',
        $this->project.'/tests/ImporterTest.php',
    ]);

    expect($result->analyzedFiles)->toBe(['src/Importer.php'])
        ->and(findingPaths($result))->toBe(['src/Importer.php']);
});

it('matches paths however the caller spells their separators', function (): void {
    $result = Sloppy::forProject($this->project)->analyzePaths([
        str_replace('/', DIRECTORY_SEPARATOR, $this->project.'/src/Exporter.php'),
    ]);

    expect(findingPaths($result))->toBe(['src/Exporter.php']);
});

it('reports nothing, and parses nothing, when no given file is covered', function (): void {
    $result = Sloppy::forProject($this->project)->analyzePaths([$this->project.'/tests/ImporterTest.php']);

    expect($result->analyzedFiles)->toBe([])
        ->and($result->findings)->toBe([]);
});

it('drops what the baseline accepts unless told not to', function (): void {
    $sloppy = Sloppy::forProject($this->project);
    $sloppy->baselines()->save($sloppy->baselines()->create($sloppy->analyze()), $sloppy->configuration->baselinePath());
    $paths = [$this->project.'/src/Importer.php'];

    expect($sloppy->analyzePaths($paths)->findings)->toBe([])
        ->and($sloppy->analyzePaths($paths, useBaseline: false)->findings)->not->toBe([]);
});

it('leaves a result alone when there is no baseline to apply', function (): void {
    $sloppy = Sloppy::forProject($this->project);
    $result = $sloppy->analyze();

    expect($sloppy->baselines()->apply($result, $sloppy->configuration->baselinePath(), $sloppy->scores()))->toBe($result);
});
