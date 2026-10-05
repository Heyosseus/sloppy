<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Git;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Analyzer;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Ast\ParsedFile;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Evidence\EvidenceCollector;
use Heyosseus\Sloppy\Scoring\Score;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;
use Heyosseus\Sloppy\Support\FileFinder;

/**
 * Compares the findings in changed files against the same files at another
 * revision.
 *
 * Both sides are scored as *whole trees*: the working tree as it is, and the
 * same tree with the changed files rewound to the base revision. That matters
 * twice over: cross-file rules such as abstraction inflation need to see
 * classes the diff did not touch, and the score is a function of the tree --
 * a diff and a scan of the same checkout must agree about it, including when
 * the change touched no PHP at all. The unchanged files are byte-identical at
 * both revisions, so the base side reuses their findings rather than running
 * every rule over them again.
 *
 * Only the findings in changed files are sorted into new, existing and
 * resolved: those are the ones the change can be held to. A caller that wants
 * nothing else can ask for an unscored comparison, which runs the rules over
 * the changed files alone.
 *
 * Matching is by finding identity -- rule, file and fingerprint -- never by line
 * number, so moving code down a file does not invent new findings.
 */
final readonly class DiffAnalyzer
{
    public function __construct(
        private Git $git,
        private Analyzer $analyzer,
        private FileFinder $finder,
        private ScoreCalculator $calculator,
        private ?EvidenceCollector $evidence = null,
    ) {}

    /**
     * Findings that come from comparing revisions rather than from rules.
     *
     * Gathered whether or not any PHP file changed: a commit that only adds
     * baseline entries is the purest form of what `SL502` looks for.
     *
     * @param  list<ChangedFile>  $changed
     * @param  list<ChangedFile>  $everything  Every file git reports changed, so a source need not ask again.
     * @return list<Finding>
     */
    private function evidenceFor(string $base, array $changed, array $everything, ?ProjectIndex $index = null): array
    {
        return $this->evidence?->collect($this->git, $base, $changed, $index, $everything) ?? [];
    }

    /**
     * @param  bool  $scored  Whether the report must carry the whole tree's
     *                        score. Without it only the changed files are run
     *                        through the rules, on both sides, and the scores
     *                        describe those files alone -- enough for a caller
     *                        that wants what the change introduced and nothing
     *                        else, such as an agent hook, and a fraction of
     *                        the cost on a large project.
     */
    public function compare(string $base, bool $scored = true): DiffReport
    {
        // A repository with no commits yet has nothing to compare against but
        // the empty tree: every file in it is new.
        $revision = $this->git->revisionExists($base) || $this->git->hasCommits()
            ? $base
            : $this->git->emptyTree();

        $projectFiles = $this->projectSources();
        $everything = $this->git->changedFiles($revision);
        $changed = $this->changedFiles($revision, $projectFiles, $everything);

        // Without a score to report, an untouched tree has nothing to say but
        // its evidence, and there is no reason to parse a file of it.
        if ($changed === [] && ! $scored) {
            return $this->unchanged($base, $revision, $everything, null);
        }

        $paths = array_fill_keys(array_map(static fn (ChangedFile $file): string => $file->relativePath, $changed), true);

        // Every file is parsed once, here, and the base tree below reuses the
        // parse of each file the diff did not touch: they are byte-identical
        // at both revisions.
        [$parsed, $errors] = $this->parse($projectFiles);
        $current = $this->analyzer->analyzeParsed(
            array_values($parsed),
            $errors,
            $scored ? null : array_keys(array_intersect_key($parsed, $paths)),
        );

        // Nothing analysable changed, so both trees are the same tree, and one
        // analysis of it answers for both.
        if ($changed === []) {
            return $this->unchanged($base, $revision, $everything, $current);
        }

        [$baseParsed, $baseErrors] = $this->baseTree($revision, $parsed, $errors, $changed);

        // Rules run on the changed files only at the base: the files the diff
        // did not touch are byte-identical at both revisions, so their
        // findings now are their findings then, and running every rule over
        // the whole project a second time would double the cost of a diff to
        // learn nothing. The whole base tree is still indexed, so cross-file
        // rules see the project as it was.
        $previous = $this->analyzer->analyzeParsed(
            array_values($baseParsed),
            $baseErrors,
            array_keys(array_intersect_key($baseParsed, $paths)),
        );
        $partition = $this->partition($this->inFiles($current, $paths), $previous->findings);

        // Both scores are calculated from rule findings alone, and evidence is
        // appended afterwards. That order is the guarantee: the score stays a
        // function of the tree, so a scan and a diff agree about it.
        return (new DiffReport(
            base: $base,
            changedFiles: $changed,
            new: $partition['new'],
            existing: $partition['existing'],
            resolved: $partition['resolved'],
            currentScore: $this->calculator->calculate($current->findings, $current->analyzedLines),
            baseScore: $scored
                ? $this->baseScore($current, $previous, $parsed, $paths)
                : $this->calculator->calculate($previous->findings, $previous->analyzedLines),
            errors: [...$previous->errors, ...$current->errors],
        ))->withExtraFindings($this->evidenceFor(
            $revision,
            array_values(array_filter($changed, static fn (ChangedFile $file): bool => $file->status !== 'deleted')),
            $everything,
            $current->index,
        ));
    }

    /**
     * A diff in which nothing analysable changed: both trees are the same
     * tree, so one analysis of it -- or none, when no score is wanted --
     * answers for both.
     *
     * @param  list<ChangedFile>  $everything
     */
    private function unchanged(string $base, string $revision, array $everything, ?AnalysisResult $current): DiffReport
    {
        $score = $current instanceof AnalysisResult
            ? $this->calculator->calculate($current->findings, $current->analyzedLines)
            : $this->calculator->calculate([], 0);

        return (new DiffReport(
            base: $base,
            changedFiles: [],
            new: [],
            existing: [],
            resolved: [],
            currentScore: $score,
            baseScore: $score,
            errors: $current->errors ?? [],
        ))->withExtraFindings($this->evidenceFor($revision, [], $everything, $current?->index));
    }

    /**
     * The base tree's score: today's findings outside the changed files are
     * their findings then, joined by the changed files' base-side findings,
     * over the line count the base tree had.
     *
     * @param  array<string, ParsedFile>  $parsed
     * @param  array<string, true>  $paths
     */
    private function baseScore(AnalysisResult $current, AnalysisResult $previous, array $parsed, array $paths): Score
    {
        return $this->calculator->calculate(
            [...$this->outsideFiles($current, $paths), ...$previous->findings],
            $current->analyzedLines - $this->codeLines($parsed, $paths) + $previous->analyzedLines,
        );
    }

    /**
     * Parse every source once.
     *
     * @param  array<string, string>  $sources
     * @return array{array<string, ParsedFile>, array<string, string>}
     */
    private function parse(array $sources): array
    {
        $parser = new Parser;
        $parsed = [];
        $errors = [];

        foreach ($sources as $path => $source) {
            $file = $parser->parse($path, $source);

            if ($file->isParsed()) {
                $parsed[$path] = $file;

                continue;
            }

            $errors[$path] = $file->parseError ?? 'Unknown parse error.';
        }

        return [$parsed, $errors];
    }

    /**
     * @param  array<string, true>  $paths
     * @return list<Finding>
     */
    private function outsideFiles(AnalysisResult $result, array $paths): array
    {
        return array_values(array_filter(
            $result->findings,
            static fn (Finding $finding): bool => ! isset($paths[$finding->location->relativePath]),
        ));
    }

    /**
     * Lines of code the analysis counted in these files today, so the base
     * tree's size can be had by swapping them for their base versions.
     *
     * @param  array<string, ParsedFile>  $parsed
     * @param  array<string, true>  $paths
     */
    private function codeLines(array $parsed, array $paths): int
    {
        $lines = 0;

        foreach (array_intersect_key($parsed, $paths) as $file) {
            $lines += $file->codeLineCount();
        }

        return $lines;
    }

    /**
     * The PHP files the comparison is about, with their hunks.
     *
     * A file deleted since the base is kept when the project would have
     * analysed it: its findings at the base are resolved by the deletion, and
     * the base score has to count them. A file renamed out of the analysed
     * paths is the same thing as far as this project is concerned.
     *
     * @param  array<string, string>  $projectFiles
     * @param  list<ChangedFile>  $everything  Every file git reports changed since the revision.
     * @return list<ChangedFile>
     */
    private function changedFiles(string $revision, array $projectFiles, array $everything): array
    {
        $present = [];
        $deleted = [];

        foreach ($everything as $file) {
            if ($file->isAnalysable() && isset($projectFiles[$file->relativePath])) {
                $present[] = $file;

                continue;
            }

            $gone = $file->status === 'deleted' ? $file->relativePath : ($file->status === 'renamed' ? $file->previousPath : null);

            if ($gone !== null && $this->finder->covers($gone)) {
                $deleted[$gone] = new ChangedFile($gone, 'deleted');
            }
        }

        $changed = [...$this->git->withHunks($revision, $present), ...array_values($deleted)];

        usort($changed, static fn (ChangedFile $a, ChangedFile $b): int => $a->relativePath <=> $b->relativePath);

        return $changed;
    }

    /**
     * The base tree, parsed: today's tree with the changed files rewound.
     * Files the diff did not touch are byte-identical at both revisions, so
     * neither reading them from git again nor parsing them again would learn
     * anything; their parse is today's.
     *
     * A renamed file is read from its old path but filed under its new one,
     * so its findings keep the identity they have today and the move alone
     * reports nothing new.
     *
     * @param  array<string, ParsedFile>  $parsed
     * @param  array<string, string>  $errors
     * @param  list<ChangedFile>  $changed
     * @return array{array<string, ParsedFile>, array<string, string>}
     */
    private function baseTree(string $revision, array $parsed, array $errors, array $changed): array
    {
        $parser = new Parser;
        $wanted = [];

        foreach ($changed as $file) {
            if ($file->existedBefore()) {
                $wanted[$file->relativePath] = $file->previousPath ?? $file->relativePath;
            }
        }

        $contents = $this->git->showFiles($revision, array_values(array_unique($wanted)));

        foreach ($changed as $file) {
            $path = $file->relativePath;
            $source = isset($wanted[$path]) ? $contents[$wanted[$path]] ?? null : null;

            unset($parsed[$path], $errors[$path]);

            if ($source === null) {
                continue;
            }

            $base = $parser->parse($path, $source);

            if ($base->isParsed()) {
                $parsed[$path] = $base;
            } else {
                $errors[$path] = $base->parseError ?? 'Unknown parse error.';
            }
        }

        // A deleted file is added at the end; analysing in path order keeps
        // the base run in the same order a scan of the base would use.
        ksort($parsed);

        return [$parsed, $errors];
    }

    /**
     * @param  array<string, true>  $paths
     * @return list<Finding>
     */
    private function inFiles(AnalysisResult $result, array $paths): array
    {
        return array_values(array_filter(
            $result->findings,
            static fn (Finding $finding): bool => isset($paths[$finding->location->relativePath]),
        ));
    }

    /**
     * Every analysable file in the project, keyed by relative path.
     *
     * @return array<string, string>
     */
    private function projectSources(): array
    {
        $sources = [];

        foreach ($this->finder->find() as $absolute) {
            $contents = file_get_contents($absolute);

            if ($contents !== false) {
                $sources[$this->finder->relative($absolute)] = $contents;
            }
        }

        return $sources;
    }

    /**
     * @param  list<Finding>  $current
     * @param  list<Finding>  $previous
     * @return array{new: list<Finding>, existing: list<Finding>, resolved: list<Finding>}
     */
    private function partition(array $current, array $previous): array
    {
        $before = $this->countByIdentity($previous);
        $after = $this->countByIdentity($current);

        $new = [];
        $existing = [];
        $seen = [];

        foreach ($current as $finding) {
            $id = $finding->identity();
            $index = $seen[$id] = ($seen[$id] ?? 0) + 1;

            // A finding that appeared once before and three times now is two
            // new findings, not zero.
            if ($index <= ($before[$id] ?? 0)) {
                $existing[] = $finding;

                continue;
            }

            $new[] = $finding;
        }

        $resolvedSeen = [];
        $resolved = [];

        foreach ($previous as $finding) {
            $id = $finding->identity();
            $index = $resolvedSeen[$id] = ($resolvedSeen[$id] ?? 0) + 1;

            if ($index > ($after[$id] ?? 0)) {
                $resolved[] = $finding;
            }
        }

        return ['new' => $new, 'existing' => $existing, 'resolved' => $resolved];
    }

    /**
     * @param  list<Finding>  $findings
     * @return array<string, int>
     */
    private function countByIdentity(array $findings): array
    {
        $counts = [];

        foreach ($findings as $finding) {
            $id = $finding->identity();
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        return $counts;
    }
}
