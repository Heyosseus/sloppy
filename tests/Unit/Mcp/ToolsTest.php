<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Mcp\ProjectResolver;
use Heyosseus\Sloppy\Mcp\Tools\DiffTool;
use Heyosseus\Sloppy\Mcp\Tools\HealthTool;
use Heyosseus\Sloppy\Mcp\Tools\RulesTool;
use Heyosseus\Sloppy\Mcp\Tools\ScanTool;
use Heyosseus\Sloppy\Mcp\Tools\ToolArguments;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

/**
 * A project an agent might ask about: one bad file, one clean one.
 */
function toolProject(): string
{
    return tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'sloppy.php' => "<?php\n\nreturn ['paths' => ['src'], 'fail_on' => 'high'];\n",
        'src/Bad.php' => godMethodSource(),
        'src/Fine.php' => "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n",
    ]);
}

it('reads arguments a model may have sent in the wrong shape', function (): void {
    $arguments = new ToolArguments([
        'project' => '  /srv/app  ',
        'blank' => '   ',
        'min_confidence' => '70',
        'count' => 3,
        'fresh' => true,
        'paths' => ['src', '  app  ', 42, ''],
        'single' => 'src/One.php',
        'rules' => 'SL101',
        'empty' => '',
        'wrong' => 12,
    ]);

    expect($arguments->string('project'))->toBe('/srv/app')
        ->and($arguments->string('blank'))->toBeNull()
        ->and($arguments->string('missing'))->toBeNull()
        ->and($arguments->int('min_confidence'))->toBe(70)
        ->and($arguments->int('count'))->toBe(3)
        ->and($arguments->int('blank'))->toBeNull()
        ->and($arguments->bool('fresh'))->toBeTrue()
        ->and($arguments->bool('missing'))->toBeFalse()
        ->and($arguments->bool('missing', true))->toBeTrue()
        ->and($arguments->strings('paths'))->toBe(['src', 'app'])
        ->and($arguments->strings('single'))->toBe(['src/One.php'])
        ->and($arguments->strings('rules'))->toBe(['SL101'])
        ->and($arguments->strings('empty'))->toBe([])
        ->and($arguments->strings('wrong'))->toBe([])
        ->and($arguments->strings('missing'))->toBe([]);
});

it('resolves the project the server was started in, or one an argument names', function (): void {
    $root = toolProject();
    $resolver = new ProjectResolver($root);

    // The locator resolves the path, which on Windows turns a short 8.3
    // directory name into its long form -- so compare against the same.
    $resolved = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');

    expect($resolver->resolve(null)->configuration->basePath)->toBe($resolved)
        ->and($resolver->resolve($root)->configuration->basePath)->toBe($resolved)
        ->and($resolver->resolve(null)->configuration->paths())->toBe(['src']);

    removeTree($root);
});

it('scans a project and reports what it found', function (): void {
    $root = toolProject();
    $tool = new ScanTool(new ProjectResolver($root));

    $markdown = $tool->call([]);

    expect($tool->name())->toBe('sloppy_scan')
        ->and($tool->description())->toContain('before reporting')
        ->and($tool->inputSchema()['type'])->toBe('object')
        ->and($markdown)->toContain('## Sloppy')
        ->and($markdown)->toContain('SL101');

    removeTree($root);
});

it('narrows a scan to the files and rules the agent just touched', function (): void {
    $root = toolProject();
    $tool = new ScanTool(new ProjectResolver($root));

    $clean = $tool->call(['paths' => ['src/Fine.php'], 'project' => $root]);
    $filtered = $tool->call(['rules' => ['SL107'], 'min_confidence' => 90]);

    expect($clean)->toContain('Nothing to report.')
        ->and($filtered)->not->toContain('SL101');

    removeTree($root);
});

it('answers a scan as JSON when asked', function (): void {
    $root = toolProject();

    /** @var array{schema: int, findings: list<array<string, mixed>>} $decoded */
    $decoded = json_decode((new ScanTool(new ProjectResolver($root)))->call(['format' => 'json']), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['schema'])->toBe(1)
        ->and($decoded['findings'])->not->toBeEmpty();

    removeTree($root);
});

it('lists every rule in force, and one rule in full', function (): void {
    $root = toolProject();
    $tool = new RulesTool(new ProjectResolver($root));

    $all = $tool->call([]);
    $one = $tool->call(['rule' => 'sl101']);

    /** @var array{rules: list<array<string, string>>} $json */
    $json = json_decode($tool->call(['format' => 'json']), true, 512, JSON_THROW_ON_ERROR);

    expect($tool->name())->toBe('sloppy_rules')
        ->and($tool->inputSchema())->toHaveKey('properties')
        ->and($all)->toContain('### SL101 God Method')
        ->and($one)->toStartWith('# SL101 God Method')
        ->and($one)->toContain('- Write it this way instead:')
        ->and($json['rules'])->not->toBeEmpty();

    removeTree($root);
});

it('says which rules exist when asked about one that does not', function (): void {
    $root = toolProject();

    expect(fn (): string => (new RulesTool(new ProjectResolver($root)))->call(['rule' => 'SL999']))
        ->toThrow(RuntimeException::class, 'No rule [SL999] is in force here.');

    removeTree($root);
});

it('reports the project health, cached, with what to read first', function (): void {
    $root = toolProject();
    $tool = new HealthTool(new ProjectResolver($root));

    $report = $tool->call([]);

    expect($tool->name())->toBe('sloppy_health')
        ->and($tool->description())->toContain('slop score')
        ->and($tool->inputSchema())->toHaveKey('properties')
        ->and($report)->toContain('/100')
        ->and($report)->toContain('## Read first')
        ->and($report)->toContain('`SL101` God Method')
        ->and($tool->call(['fresh' => true]))->toContain('/100');

    removeTree($root);
});

it('says nothing is flagged for a clean project', function (): void {
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Fine.php' => "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n",
    ]);

    expect((new HealthTool(new ProjectResolver($root)))->call([]))->toContain('Nothing flagged.');

    removeTree($root);
});

it('refuses to diff outside a git repository, and against a revision that is not there', function (): void {
    $root = toolProject();
    $tool = new DiffTool(new ProjectResolver($root));

    expect($tool->name())->toBe('sloppy_diff')
        ->and($tool->description())->toContain('introduced')
        ->and($tool->inputSchema())->toHaveKey('properties')
        ->and(fn (): string => $tool->call([]))->toThrow(RuntimeException::class, 'is not a git repository');

    removeTree($root);
});

it('reports what the working tree added, in markdown and in JSON', function (): void {
    $repository = TempRepository::create()
        ->write('composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}')
        ->write('sloppy.php', "<?php\n\nreturn ['paths' => ['src']];\n")
        ->write('src/Fine.php', "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n")
        ->commit('first')
        ->write('src/Bad.php', godMethodSource());

    $tool = new DiffTool(new ProjectResolver($repository->path));

    /** @var array{mode: string, summary: array{new: int}} $json */
    $json = json_decode($tool->call(['base' => 'HEAD', 'format' => 'json']), true, 512, JSON_THROW_ON_ERROR);

    expect($tool->call(['base' => 'HEAD']))->toContain('SL101')
        ->and($json['mode'])->toBe('diff')
        ->and($json['summary']['new'])->toBe(1)
        ->and(fn (): string => $tool->call(['base' => 'v9.9.9']))
        ->toThrow(RuntimeException::class, 'Revision [v9.9.9] could not be resolved in this repository.');

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');
