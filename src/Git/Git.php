<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Git;

use Symfony\Component\Process\Process;

/**
 * The only place in the package that shells out.
 *
 * Arguments are always passed as an array so nothing is interpolated into a
 * shell string, and the repository root is fixed at construction.
 */
final readonly class Git
{
    public function __construct(
        private string $workingDirectory,
        private float $timeout = 60.0,
    ) {}

    public function isAvailable(): bool
    {
        return $this->attempt(['--version']) !== null;
    }

    public function isRepository(): bool
    {
        return trim((string) $this->attempt(['rev-parse', '--is-inside-work-tree'])) === 'true';
    }

    /**
     * @param  list<string>  $args
     *
     * @throws GitException
     */
    public function run(array $args): string
    {
        $process = new Process(['git', ...$args], $this->workingDirectory, timeout: $this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new GitException(sprintf(
                'git %s failed: %s',
                implode(' ', $args),
                trim($process->getErrorOutput()) !== '' ? trim($process->getErrorOutput()) : 'exit code '.$process->getExitCode(),
            ));
        }

        return $process->getOutput();
    }

    /**
     * Run a command, returning null instead of throwing when it fails.
     *
     * @param  list<string>  $args
     */
    public function attempt(array $args): ?string
    {
        $process = new Process(['git', ...$args], $this->workingDirectory, timeout: $this->timeout);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /**
     * Whether a revision exists and can be resolved.
     */
    public function revisionExists(string $revision): bool
    {
        return $this->attempt(['rev-parse', '--verify', '--quiet', $revision.'^{commit}']) !== null;
    }

    public function currentBranch(): ?string
    {
        $branch = $this->attempt(['rev-parse', '--abbrev-ref', 'HEAD']);

        return $branch === null ? null : trim($branch);
    }

    /**
     * A file's contents at a revision, or null when it did not exist there.
     */
    public function showFile(string $revision, string $relativePath): ?string
    {
        return $this->attempt(['show', $revision.':'.$relativePath]);
    }

    /**
     * Files that differ between a revision and the working tree, including
     * files git is not tracking yet.
     *
     * Untracked files matter here: an agent that adds a new class has not
     * staged it, and a review that ignored it would miss the code most likely
     * to need reviewing.
     *
     * @return list<ChangedFile>
     */
    public function changedFiles(string $base): array
    {
        $files = [];

        foreach ($this->parseNameStatus($this->run(['diff', '--name-status', '--find-renames', $base])) as $file) {
            $files[$file->relativePath] = $file;
        }

        foreach ($this->untrackedFiles() as $path) {
            $files[$path] ??= new ChangedFile($path, 'untracked');
        }

        $result = array_values($files);

        usort($result, static fn (ChangedFile $a, ChangedFile $b): int => $a->relativePath <=> $b->relativePath);

        return $result;
    }

    /**
     * Added line ranges in the new version of a file.
     *
     * @return list<DiffHunk>
     */
    public function hunksFor(string $base, string $relativePath): array
    {
        $diff = $this->attempt(['diff', '--unified=0', '--no-color', $base, '--', $relativePath]);

        if ($diff === null) {
            return [];
        }

        $hunks = [];

        foreach (explode("\n", $diff) as $line) {
            if (! str_starts_with($line, '@@')) {
                continue;
            }

            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matches) !== 1) {
                continue;
            }

            $hunks[] = new DiffHunk(
                startLine: (int) $matches[1],
                lineCount: isset($matches[2]) ? (int) $matches[2] : 1,
            );
        }

        return $hunks;
    }

    /**
     * The same file with its changed line ranges filled in.
     *
     * Hunks cost a `git diff` or a file read each, and only the files that
     * survive the project's own path filtering are ever asked about, so the
     * caller decides which files are worth the work. An untracked `vendor`
     * directory is the reason that decision does not belong here.
     */
    public function withHunks(string $base, ChangedFile $file): ChangedFile
    {
        if (! $file->isAnalysable()) {
            return $file;
        }

        return new ChangedFile(
            relativePath: $file->relativePath,
            status: $file->status,
            // A wholly new file has no hunks against the base, but every line
            // in it is new, so the file is its own hunk. Without this a review
            // of a brand new class would report that it touched no lines.
            hunks: $file->existedBefore()
                ? $this->hunksFor($base, $file->relativePath)
                : $this->wholeFileHunks($file->relativePath),
            previousPath: $file->previousPath,
        );
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

    /**
     * @return list<string>
     */
    public function untrackedFiles(): array
    {
        $output = $this->attempt(['ls-files', '--others', '--exclude-standard']);

        if ($output === null) {
            return [];
        }

        $paths = [];

        foreach (explode("\n", $output) as $line) {
            $path = trim($line);

            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @return list<ChangedFile>
     */
    private function parseNameStatus(string $output): array
    {
        $files = [];

        foreach (explode("\n", $output) as $line) {
            $parts = preg_split('/\t/', trim($line)) ?: [];

            if (count($parts) < 2) {
                continue;
            }

            $code = $parts[0];
            $status = match (true) {
                str_starts_with($code, 'A') => 'added',
                str_starts_with($code, 'D') => 'deleted',
                str_starts_with($code, 'R') => 'renamed',
                default => 'modified',
            };

            // Renames are reported as "R100\told\tnew".
            $path = $status === 'renamed' && isset($parts[2]) ? $parts[2] : $parts[1];
            $previous = $status === 'renamed' ? $parts[1] : null;

            $files[] = new ChangedFile($path, $status, previousPath: $previous);
        }

        return $files;
    }
}
