<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

use Heyosseus\Sloppy\Ast\ParsedFile;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;
use Throwable;

/**
 * Runs every enabled rule over a set of files.
 *
 * The run is two passes. The first parses each file once and builds the project
 * index; the second hands each parsed file to each rule. Nothing re-reads or
 * re-parses a file, and no rule can see a half-built index.
 */
final readonly class Analyzer
{
    public function __construct(
        private Parser $parser,
        private RuleRegistry $registry,
        private ScoreCalculator $calculator,
        private int $minConfidence = 0,
    ) {}

    /**
     * @param  array<string, string>  $files  Relative path => absolute path.
     * @param  list<string>|null  $only  Restrict rule execution to these relative paths, while still indexing every file.
     * @param  (callable(string): void)|null  $onFile  Progress callback, given each relative path.
     */
    public function analyze(array $files, ?array $only = null, ?callable $onFile = null): AnalysisResult
    {
        $parsed = [];
        $errors = [];

        foreach ($files as $relative => $absolute) {
            $file = $this->parser->parseFile($absolute, $relative);

            if (! $file->isParsed()) {
                $errors[$relative] = $file->parseError ?? 'Unknown parse error.';

                continue;
            }

            $parsed[] = $file;

            if ($onFile !== null) {
                $onFile($relative);
            }
        }

        return $this->run($parsed, $errors, $only);
    }

    /**
     * Analyse source text that may not exist on disk.
     *
     * Diff mode uses this to analyse a file as it looked at another revision,
     * and tests use it to avoid fixture files for one-off snippets.
     *
     * @param  array<string, string>  $sources  Relative path => source code.
     * @param  list<string>|null  $only  Restrict rule execution to these relative paths, while still indexing every file.
     */
    public function analyzeSources(array $sources, ?array $only = null): AnalysisResult
    {
        $parsed = [];
        $errors = [];

        foreach ($sources as $relative => $source) {
            $file = $this->parser->parse($relative, $source);

            if ($file->isParsed()) {
                $parsed[] = $file;

                continue;
            }

            $errors[$relative] = $file->parseError ?? 'Unknown parse error.';
        }

        return $this->run($parsed, $errors, $only);
    }

    /**
     * Cross-file rules need the whole project in the index even when only a
     * few files are being reported on, which is why indexing and reporting are
     * separate concerns here.
     *
     * @param  list<ParsedFile>  $files
     * @param  array<string, string>  $errors
     * @param  list<string>|null  $only
     */
    private function run(array $files, array $errors, ?array $only = null): AnalysisResult
    {
        $index = ProjectIndex::build($files);
        $reportable = $only === null ? null : array_fill_keys($only, true);
        $findings = [];
        $lines = 0;
        $analyzed = [];

        foreach ($files as $file) {
            if ($reportable !== null && ! isset($reportable[$file->relativePath])) {
                continue;
            }

            $analyzed[] = $file->relativePath;
            $lines += $file->codeLineCount();

            $findings = [...$findings, ...$this->inspect(new AnalysisContext($file, $index), $errors)];
        }

        sort($analyzed);

        return AnalysisResult::create(
            findings: $findings,
            analyzedFiles: $analyzed,
            analyzedLines: $lines,
            calculator: $this->calculator,
            errors: $errors,
        );
    }

    /**
     * Every rule's verdict on one file.
     *
     * @param  array<string, string>  $errors
     * @return list<Finding>
     */
    private function inspect(AnalysisContext $context, array &$errors): array
    {
        $findings = [];

        foreach ($this->registry->rules() as $rule) {
            try {
                foreach ($rule->analyze($context) as $finding) {
                    if ($finding->confidence >= $this->minConfidence) {
                        $findings[] = $finding;
                    }
                }
            } catch (Throwable $exception) {
                // A rule that blows up on one unusual file must not take the
                // rest of the run with it, but it must not disappear either.
                $errors[$rule->id().' in '.$context->relativePath()] = $exception->getMessage();
            }
        }

        return $findings;
    }
}
