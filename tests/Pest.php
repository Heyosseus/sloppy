<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Location;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\ParsedFile;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Contracts\Rule;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RuleTester;
use Heyosseus\Sloppy\Tests\Support\TempRepository;
use Heyosseus\Sloppy\Tests\Support\TempTree;
use Heyosseus\Sloppy\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;

// Laravel only where a test uses it: the artisan commands, the service
// provider and the Filament views. Testbench builds a whole application for
// every test it wraps, which cost the ~1,900 tests that never touch it a
// tenth of a second or more each -- more than the tests themselves.
uses(TestCase::class)->in(
    __DIR__.'/Feature/Console',
    __DIR__.'/Feature/ServiceProviderTest.php',
    __DIR__.'/Feature/Integrations/FilamentTest.php',
    __DIR__.'/Feature/Watch/WatchCommandsTest.php',
);

hermeticGit();
cachePerWorker();

/**
 * Stop git from climbing out of the system temp directory.
 *
 * The throwaway projects the tests create live in the temp directory, and
 * several tests rely on one of them *not* being a repository. On a machine
 * whose temp directory sits inside some other checkout (a home directory kept
 * in git, say) git would happily discover that outer repository instead. A
 * ceiling at the temp directory keeps every test project's answer its own.
 * Written to the environment, `$_ENV` and `$_SERVER` alike, because Symfony
 * Process builds a child's environment from all three.
 */
function hermeticGit(): void
{
    $temp = sys_get_temp_dir();
    $ceilings = array_values(array_unique(array_filter([$temp, realpath($temp)], is_string(...))));
    $existing = getenv('GIT_CEILING_DIRECTORIES');

    if (is_string($existing) && $existing !== '') {
        $ceilings[] = $existing;
    }

    $value = implode(PATH_SEPARATOR, $ceilings);

    putenv('GIT_CEILING_DIRECTORIES='.$value);
    $_ENV['GIT_CEILING_DIRECTORIES'] = $value;
    $_SERVER['GIT_CEILING_DIRECTORIES'] = $value;
}

/**
 * Give each parallel worker its own Laravel manifest files.
 *
 * Testbench writes `bootstrap/cache/services.php` and `packages.php` inside
 * vendor by writing a temporary file and renaming it over the manifest. Under
 * `--parallel` every worker does so at once, and Windows refuses to rename
 * over a file another process has open: the test that lost the race failed
 * with "Access is denied". Paratest numbers its workers in `TEST_TOKEN`; a
 * serial run has none and keeps the shared files. The paths are relative,
 * because Laravel reads `C:\...` as relative to the base path anyway.
 */
function cachePerWorker(): void
{
    $token = getenv('TEST_TOKEN');

    if (! is_string($token) || $token === '') {
        return;
    }

    foreach (['APP_SERVICES_CACHE' => 'services', 'APP_PACKAGES_CACHE' => 'packages'] as $key => $name) {
        $value = sprintf('bootstrap/cache/%s-%s.php', $name, $token);

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

/**
 * Parse a snippet, adding the opening tag when the test left it out.
 */
function parsedFile(string $code): ParsedFile
{
    $trimmed = ltrim($code);

    return (new Parser)->parse('app/Example.php', str_starts_with($trimmed, '<?php') ? $trimmed : "<?php\n\n".$trimmed);
}

function firstClass(string $code): ClassLike
{
    return parsedFile($code)->classLikes()[0];
}

function firstMethod(string $code): ClassMethod
{
    return NodeHelper::methods(firstClass($code))[0];
}

/**
 * A finding with sensible defaults, for tests about everything downstream of
 * rules: sorting, scoring, baselines, formatting.
 *
 * @param  array<string, string|int|float|bool>  $metrics
 */
function finding(
    string $rule = 'SL101',
    string $file = 'app/Order.php',
    int $line = 10,
    ?int $endLine = null,
    string $fingerprint = 'Order::store',
    int $confidence = 90,
    Severity $severity = Severity::High,
    array $metrics = [],
    string $name = 'God Method',
    Category $category = Category::Complexity,
): Finding {
    return new Finding(
        ruleId: $rule,
        ruleName: $name,
        category: $category,
        severity: $severity,
        confidence: $confidence,
        location: new Location($file, $line, $endLine),
        message: 'A message.',
        explanation: 'An explanation.',
        suggestion: 'A suggestion.',
        fingerprint: $fingerprint,
        metrics: $metrics,
    );
}

/**
 * A finished run, for the tests about what happens to one: snapshots,
 * dashboards, Code Quality reports, generated Rector configs.
 *
 * @param  list<Finding>  $findings
 * @param  list<string>  $files
 * @param  list<string>  $skippedRules
 */
function analysisResult(
    array $findings = [],
    array $files = ['app/Order.php'],
    int $lines = 500,
    array $skippedRules = [],
): AnalysisResult {
    return AnalysisResult::create(
        findings: $findings,
        analyzedFiles: $files,
        analyzedLines: $lines,
        calculator: new ScoreCalculator,
        skippedRules: $skippedRules,
    );
}

/**
 * Run one rule over a snippet.
 *
 * @return list<Finding>
 */
function findings(Rule $rule, string $code, string $path = 'app/Example.php'): array
{
    return RuleTester::run($rule, $code, $path);
}

/**
 * Run one rule over several files.
 *
 * @param  array<string, string>  $files
 * @return list<Finding>
 */
function findingsAcross(Rule $rule, array $files): array
{
    return RuleTester::runAcross($rule, $files);
}

/**
 * Run one rule over several files, judged against an architecture.
 *
 * @param  array<string, string>  $files
 * @param  array<string, mixed>  $architecture  As `sloppy.architecture`.
 * @return list<Finding>
 */
function findingsUnder(Rule $rule, array $files, array $architecture): array
{
    return RuleTester::runAcross($rule, $files, Profile::fromArray($architecture));
}

/**
 * A throwaway project tree on disk, with forward slashes throughout, because
 * that is what Sloppy reports and what a Windows temp path does not give us.
 *
 * @param  array<string, string>  $files  Relative path => contents.
 * @return string The project root.
 */
function tempProject(array $files = []): string
{
    $root = TempTree::track(str_replace('\\', '/', sys_get_temp_dir()).'/sloppy-'.bin2hex(random_bytes(6)));

    if ($files === []) {
        mkdir($root, 0o777, true);
    }

    foreach ($files as $path => $contents) {
        $full = $root.'/'.$path;
        $directory = dirname($full);

        if (! is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($full, $contents);
    }

    return $root;
}

function removeTree(string $path): void
{
    TempTree::remove($path);
}

/**
 * Rule IDs from a set of findings.
 *
 * @param  list<Finding>  $findings
 * @return list<string>
 */
function ruleIds(array $findings): array
{
    return RuleTester::ids($findings);
}

/**
 * Every message from a set of findings, joined -- convenient for asserting
 * that a report mentions a particular measurement.
 *
 * @param  list<Finding>  $findings
 */
function messages(array $findings): string
{
    return implode("\n", array_map(static fn (Finding $finding): string => $finding->message, $findings));
}

/**
 * A class with one method that trips SL101 (God Method), for tests that need
 * a finding without depending on fixture files other suites assert on.
 */
function godMethodSource(): string
{
    $body = '';

    // SL101 fires when TWO of its signals are exceeded (defaults: max_lines 80,
    // max_statements 40, max_calls 25). One hundred call-bearing assignments
    // clear three of them, so the fixture does not rest on a single threshold
    // staying where it is.
    for ($i = 1; $i <= 100; $i++) {
        $body .= sprintf("        \$value%d = strlen('x%d');\n", $i, $i);
    }

    return "<?php\n\nclass Bad\n{\n    public function run(): void\n    {\n".$body."    }\n}\n";
}

/**
 * A project sorted into roles, from source strings rather than files.
 *
 * @param  array<string, string>  $files  Relative path => source.
 * @param  array<string, mixed>  $architecture  The `sloppy.architecture` array.
 */
function architectureSnapshotOf(array $files, array $architecture = []): Heyosseus\Sloppy\Architecture\ArchitectureSnapshot
{
    $parser = new Parser;

    return Heyosseus\Sloppy\Architecture\ArchitectureSnapshot::fromFiles(
        new Heyosseus\Sloppy\Architecture\ArchitectureMap(Profile::fromArray($architecture)),
        array_values(array_map(
            static fn (string $source, string $path): ParsedFile => $parser->parse($path, $source),
            $files,
            array_keys($files),
        )),
    );
}

/**
 * Point the Laravel package at a throwaway project and re-bind the container so the
 * command under test analyses it.
 *
 * @param  array<string, string>  $files  Relative path => contents.
 * @param  array<string, mixed>  $config
 */
function project(array $files, array $config = []): string
{
    $root = tempProject($files);

    /** @var Repository $repository */
    $repository = app(Repository::class);

    /** @var array<string, mixed> $current */
    $current = $repository->get('sloppy', []);

    $merged = [...$current, ...$config];
    $repository->set('sloppy', $merged);

    app()->instance(Configuration::class, Configuration::fromArray($merged, $root));
    app()->instance(Sloppy::class, new Sloppy(Configuration::fromArray($merged, $root)));

    return $root;
}

/**
 * Bind the container to a throwaway git repository.
 *
 * @param  array<string, mixed>  $config
 */
function bindRepository(TempRepository $repository, array $config = []): void
{
    $merged = [
        'paths' => ['app'],
        'exclude' => ['vendor'],
        'fail_on' => 'high',
        ...$config,
    ];

    app()->instance(Configuration::class, Configuration::fromArray($merged, $repository->path));
    app()->instance(Sloppy::class, new Sloppy(Configuration::fromArray($merged, $repository->path)));
}

/**
 * @param  array<string, string>  $files
 */
function indexOf(array $files): ProjectIndex
{
    $parser = new Parser;
    $parsed = [];

    foreach ($files as $path => $source) {
        $trimmed = ltrim($source);
        $parsed[] = $parser->parse($path, str_starts_with($trimmed, '<?php') ? $trimmed : "<?php\n\n".$trimmed);
    }

    return ProjectIndex::build($parsed);
}

function neonFor(string $path, int $count): string
{
    return "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: \"#^Something\.$#\"\n\t\t\tcount: {$count}\n\t\t\tpath: {$path}\n";
}

function writeReport(string $name, string $contents): string
{
    $root = tempProject([]);
    file_put_contents($root.'/'.$name, $contents);

    return $root;
}

/**
 * A controller that swallows every exception: one SL107 finding.
 */
const SWALLOWING_CONTROLLER = <<<'PHP'
<?php

namespace App\Http\Controllers;

class OrderController
{
    public function show(int $id)
    {
        try {
            return $this->records->find($id);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
PHP;
