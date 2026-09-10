<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Tests\Support;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Analyzer;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\RuleRegistry;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Contracts\Rule;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;
use RuntimeException;

/**
 * Runs one rule over a snippet, so a rule test reads as "this code produces
 * this finding" with nothing else in the way.
 */
final class RuleTester
{
    private function __construct() {}

    /**
     * @return list<Finding>
     */
    public static function run(Rule $rule, string $code, string $path = 'app/Example.php'): array
    {
        return self::runAcross($rule, [$path => $code]);
    }

    /**
     * Run a rule over several files, which is what the cross-file rules need.
     *
     * @param  array<string, string>  $files  Relative path => source.
     * @return list<Finding>
     */
    public static function runAcross(Rule $rule, array $files): array
    {
        $parser = new Parser;
        $parsed = [];

        foreach ($files as $path => $source) {
            $file = $parser->parse($path, self::normalise($source));

            if (! $file->isParsed()) {
                throw new RuntimeException(sprintf('Fixture [%s] does not parse: %s', $path, (string) $file->parseError));
            }

            $parsed[] = $file;
        }

        $index = ProjectIndex::build($parsed);
        $findings = [];

        foreach ($parsed as $file) {
            foreach ($rule->analyze(new AnalysisContext($file, $index)) as $finding) {
                $findings[] = $finding;
            }
        }

        return AnalysisResult::sort($findings);
    }

    /**
     * Every shipped rule over a set of sources, for the fixture-wide tests.
     *
     * @param  array<string, string>  $files
     */
    public static function runAll(array $files): AnalysisResult
    {
        $analyzer = new Analyzer(new Parser, RuleRegistry::withDefaults(), new ScoreCalculator);

        return $analyzer->analyzeSources(array_map(self::normalise(...), $files));
    }

    /**
     * Read a file from tests/Fixtures.
     */
    public static function fixture(string $relativePath): string
    {
        $path = dirname(__DIR__).'/Fixtures/'.ltrim($relativePath, '/');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Fixture [%s] could not be read.', $path));
        }

        return $contents;
    }

    /**
     * Every fixture in a directory, keyed as if it lived under app/.
     *
     * @return array<string, string>
     */
    public static function fixtureDirectory(string $directory): array
    {
        $paths = glob(dirname(__DIR__).'/Fixtures/'.trim($directory, '/').'/*.php');
        $files = [];

        foreach ($paths === false ? [] : $paths as $path) {
            $files['app/'.basename($path)] = (string) file_get_contents($path);
        }

        return $files;
    }

    /**
     * Rule IDs present in a set of findings, with duplicates.
     *
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    public static function ids(array $findings): array
    {
        return array_map(static fn (Finding $finding): string => $finding->ruleId, $findings);
    }

    /**
     * Fingerprints present in a set of findings.
     *
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    public static function fingerprints(array $findings): array
    {
        return array_map(static fn (Finding $finding): string => $finding->fingerprint, $findings);
    }

    /**
     * Tests write snippets without a leading `<?php`, so it is added here.
     */
    private static function normalise(string $source): string
    {
        $trimmed = ltrim($source);

        return str_starts_with($trimmed, '<?php') ? $trimmed : "<?php\n\n".$trimmed;
    }
}
