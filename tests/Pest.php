<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Location;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\ParsedFile;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Contracts\Rule;
use Heyosseus\Sloppy\Tests\Support\RuleTester;
use Heyosseus\Sloppy\Tests\TestCase;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;

uses(TestCase::class)->in(__DIR__.'/Unit', __DIR__.'/Feature');

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
 * A throwaway project tree on disk, with forward slashes throughout, because
 * that is what Sloppy reports and what a Windows temp path does not give us.
 *
 * @param  array<string, string>  $files  Relative path => contents.
 * @return string The project root.
 */
function tempProject(array $files = []): string
{
    $root = str_replace('\\', '/', sys_get_temp_dir()).'/sloppy-'.bin2hex(random_bytes(6));

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
    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $full = $path.'/'.$entry;

        is_dir($full) ? removeTree($full) : unlink($full);
    }

    rmdir($path);
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
