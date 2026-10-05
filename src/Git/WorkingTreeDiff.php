<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Git;

/**
 * What changed between a revision and the working tree: which files, and
 * which of their lines.
 *
 * Every process goes through `Git`, so it is still the only class that shells
 * out; this one decides what to ask and puts the answers together.
 */
final readonly class WorkingTreeDiff
{
    /**
     * What every diff is run with, whatever the user's git configuration says.
     *
     * Each of these undoes a setting that changes the shape of the output this
     * class parses: `diff.mnemonicPrefix`, `diff.noprefix`, `diff.srcPrefix`
     * and `diff.dstPrefix` move the `a/` and `b/` prefixes, `color.diff=always`
     * wraps hunk headers in escape codes, and an external diff driver or a
     * textconv filter replaces the unified diff with whatever it prints.
     * `--relative` keeps paths -- and the files considered -- to the working
     * directory, which is what a project in a subdirectory of a repository
     * needs.
     *
     * @var list<string>
     */
    private const array DIFF_FLAGS = [
        '--no-color',
        '--no-ext-diff',
        '--no-textconv',
        '--src-prefix=a/',
        '--dst-prefix=b/',
        '--relative',
    ];

    private IntentToAddIndex $index;

    public function __construct(
        private Git $git,
        private string $workingDirectory,
        private GitOutputParser $parser = new GitOutputParser,
    ) {
        $this->index = new IntentToAddIndex($git, $workingDirectory, $parser);
    }

    /**
     * Files that differ between a revision and the working tree, including
     * files git is not tracking yet.
     *
     * Untracked files matter here: an agent that adds a new class has not
     * staged it, and a review that ignored it would miss the code most likely
     * to need reviewing. They take part in rename detection too, so a file
     * moved with a plain `mv` is the rename it is rather than a deletion and
     * an unrelated new file.
     *
     * @return list<ChangedFile>
     */
    public function changedFiles(string $base): array
    {
        $untracked = $this->git->untrackedFiles();
        $php = array_values(array_filter($untracked, static fn (string $path): bool => str_ends_with($path, '.php')));
        $files = [];

        $this->index->with($php, function (array $env) use ($base, &$files): void {
            foreach ($this->parser->nameStatus($this->git->run(['diff', '--name-status', '-z', '--find-renames', ...self::DIFF_FLAGS, $base], $env)) as $file) {
                $files[$file->relativePath] = $file;
            }
        });

        $untrackedSet = array_fill_keys($untracked, true);

        foreach ($files as $path => $file) {
            // An untracked file git only knows of through the throwaway index
            // is reported as added; it is still the untracked file it was.
            if ($file->status === 'added' && isset($untrackedSet[$path])) {
                $files[$path] = new ChangedFile($path, 'untracked');
            }
        }

        foreach ($untracked as $path) {
            $files[$path] ??= new ChangedFile($path, 'untracked');
        }

        $result = array_values($files);

        usort($result, static fn (ChangedFile $a, ChangedFile $b): int => $a->relativePath <=> $b->relativePath);

        return $result;
    }

    /**
     * The same files with their changed line ranges filled in.
     *
     * @param  list<ChangedFile>  $files
     * @return list<ChangedFile>
     */
    public function withHunks(string $base, array $files): array
    {
        $existing = [];
        $previous = [];

        foreach ($files as $file) {
            if ($file->isAnalysable() && $file->existedBefore()) {
                $existing[] = $file->relativePath;

                if ($file->previousPath !== null) {
                    $previous[$file->relativePath] = $file->previousPath;
                }
            }
        }

        $hunks = $this->hunksForFiles($base, $existing, $previous);
        $enriched = [];

        foreach ($files as $file) {
            if (! $file->isAnalysable()) {
                $enriched[] = $file;

                continue;
            }

            $enriched[] = new ChangedFile(
                relativePath: $file->relativePath,
                status: $file->status,
                // A wholly new file has no hunks against the base, but every
                // line in it is new, so the file is its own hunk. Without this
                // a review of a brand new class would report that it touched
                // no lines.
                hunks: $file->existedBefore()
                    ? ($hunks[$file->relativePath] ?? [])
                    : $this->wholeFileHunks($file->relativePath),
                previousPath: $file->previousPath,
            );
        }

        return $enriched;
    }

    /**
     * Changed line ranges for many files, batched into as few git processes as
     * the platform's command-line limit allows.
     *
     * A renamed file is asked about together with the path it came from, so
     * its hunks are what the move changed rather than the whole file; a new
     * path git is not tracking yet is marked for it in a throwaway index.
     *
     * @param  list<string>  $relativePaths
     * @param  array<string, string>  $previousPaths  New path => the path it was renamed from.
     * @return array<string, list<DiffHunk>>
     */
    public function hunksForFiles(string $base, array $relativePaths, array $previousPaths = []): array
    {
        $hunks = [];
        $pathspecs = [];

        foreach ($relativePaths as $path) {
            $pathspecs[] = $path;

            if (isset($previousPaths[$path])) {
                $pathspecs[] = $previousPaths[$path];
            }
        }

        $renamed = array_keys($previousPaths);
        $untracked = $renamed === [] ? [] : array_values(array_intersect($renamed, $this->git->untrackedFiles()));

        $this->index->with($untracked, function (array $env) use ($base, $pathspecs, &$hunks): void {
            foreach ($this->parser->batches($pathspecs) as $batch) {
                $diff = $this->git->attempt(['diff', '--unified=0', '--find-renames', ...self::DIFF_FLAGS, $base, '--', ...$batch], $env);

                if ($diff === null) {
                    continue;
                }

                foreach ($this->parser->hunks($diff) as $path => $ranges) {
                    $hunks[$path] = $ranges;
                }
            }
        });

        return $hunks;
    }

    /**
     * The single hunk covering a file that is new in its entirety.
     *
     * @return list<DiffHunk>
     */
    private function wholeFileHunks(string $relativePath): array
    {
        $contents = @file_get_contents($this->workingDirectory.'/'.$relativePath);

        if ($contents === false || trim($contents) === '') {
            return [];
        }

        return [new DiffHunk(startLine: 1, lineCount: count(explode("\n", rtrim($contents, "\n"))))];
    }
}
