<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Git;

use Symfony\Component\Process\Process;

/**
 * The only place in the package that shells out.
 *
 * Arguments are always passed as an array so nothing is interpolated into a
 * shell string, and the repository root is fixed at construction. Reading
 * git's output is `GitOutputParser`'s job, and putting a working-tree diff
 * together is `WorkingTreeDiff`'s; both run their processes through here.
 *
 * Every path this class hands out or accepts is relative to the working
 * directory, never to the repository root: a project that is one package of a
 * larger repository names its files the way it would if it were the whole
 * repository.
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
     * @param  array<string, string>  $env
     *
     * @throws GitException
     */
    public function run(array $args, array $env = []): string
    {
        $process = $this->process($args, $env);
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
     * @param  array<string, string>  $env
     */
    public function attempt(array $args, array $env = []): ?string
    {
        // A project directory that is not there cannot be asked anything, and
        // the process would refuse to start rather than fail: checking first
        // turns a stack trace out of a path typo into the same "no answer"
        // every caller of this method already handles.
        if (! is_dir($this->workingDirectory)) {
            return null;
        }

        $process = $this->process($args, $env);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /**
     * Every git process this class starts.
     *
     * Paths are asked for unquoted, because `core.quotePath` -- on by default
     * -- turns `Café.php` into `"Caf\303\251.php"`, which matches no file on
     * disk. The user's own git configuration is otherwise left alone; the diff
     * invocations neutralise the parts of it that change their output.
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     */
    private function process(array $args, array $env = [], ?string $input = null): Process
    {
        return new Process(
            ['git', '-c', 'core.quotePath=false', ...$args],
            $this->workingDirectory,
            $env === [] ? null : $env,
            $input,
            $this->timeout,
        );
    }

    /**
     * Whether a revision exists and can be resolved.
     */
    public function revisionExists(string $revision): bool
    {
        return $this->attempt(['rev-parse', '--verify', '--quiet', $revision.'^{commit}']) !== null;
    }

    /**
     * Whether the repository has any commit at all. A repository that was
     * just initialised has none, and `HEAD` names nothing yet.
     */
    public function hasCommits(): bool
    {
        return $this->revisionExists('HEAD');
    }

    /**
     * The object name of the empty tree in this repository's hash, which is
     * what "before the first commit" compares as.
     */
    public function emptyTree(): string
    {
        $process = $this->process(['hash-object', '-t', 'tree', '--stdin'], input: '');
        $process->run();
        $hash = trim($process->getOutput());

        return $process->isSuccessful() && $hash !== '' ? $hash : '4b825dc642cb6eb9a060e54bf8d69288fbee4904';
    }

    /**
     * The commit a revision names, or null when it names none.
     */
    public function commitOf(string $revision): ?string
    {
        $sha = $this->attempt(['rev-parse', '--verify', '--quiet', $revision.'^{commit}']);

        return $sha === null || trim($sha) === '' ? null : trim($sha);
    }

    /**
     * Whether a revision is a ref -- a branch, remote branch or tag -- rather
     * than a commit spelled out. A branch moves on after a change forks from
     * it; a commit is exactly what was asked for.
     */
    public function isRef(string $revision): bool
    {
        return trim((string) $this->attempt(['rev-parse', '--symbolic-full-name', $revision])) !== '';
    }

    /**
     * The best common ancestor of two revisions, or null when there is none
     * to be found -- unrelated histories, or a shallow clone that stops short
     * of it.
     */
    public function mergeBase(string $revision, string $other = 'HEAD'): ?string
    {
        $sha = $this->attempt(['merge-base', $revision, $other]);

        return $sha === null || trim($sha) === '' ? null : trim($sha);
    }

    /**
     * The working directory's path inside the repository, with a trailing
     * slash, or an empty string at the root or outside any repository.
     *
     * Reports read by the forge itself -- annotations, SARIF -- name files
     * from the repository root, while everything else in this package names
     * them from the project root.
     */
    public function prefix(): string
    {
        return trim((string) $this->attempt(['rev-parse', '--show-prefix']));
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
        return $this->showFiles($revision, [$relativePath])[$relativePath] ?? null;
    }

    /**
     * Several files' contents at a revision, in one git process.
     *
     * `git show` costs a process per file, and process startup dominates
     * everything else on a large diff -- 500 files took two minutes of pure
     * spawning. `cat-file --batch` answers the whole list from one process fed
     * on stdin.
     *
     * Each path is asked for as `<rev>:./<path>`, which git resolves from the
     * working directory; a bare `<rev>:<path>` is read from the repository
     * root and finds nothing for a project in a subdirectory.
     *
     * @param  list<string>  $relativePaths
     * @return array<string, ?string>
     */
    public function showFiles(string $revision, array $relativePaths): array
    {
        if ($relativePaths === [] || ! is_dir($this->workingDirectory)) {
            return array_fill_keys($relativePaths, null);
        }

        $process = $this->process(['cat-file', '--batch'], input: implode(
            "\n",
            array_map(static fn (string $path): string => $revision.':./'.ltrim($path, '/'), $relativePaths),
        )."\n");
        $process->run();

        if (! $process->isSuccessful()) {
            return array_fill_keys($relativePaths, null);
        }

        return (new GitOutputParser)->blobs($process->getOutput(), $relativePaths);
    }

    /**
     * Files that differ between a revision and the working tree, including
     * files git is not tracking yet, which take part in rename detection too.
     *
     * @return list<ChangedFile>
     */
    public function changedFiles(string $base): array
    {
        return $this->diff()->changedFiles($base);
    }

    /**
     * Added line ranges in the new version of a file.
     *
     * @return list<DiffHunk>
     */
    public function hunksFor(string $base, string $relativePath): array
    {
        return $this->hunksForFiles($base, [$relativePath])[$relativePath] ?? [];
    }

    /**
     * The same files with their changed line ranges filled in.
     *
     * Only the files that survive the project's own path filtering are ever
     * asked about, so the caller decides which files are worth the work -- an
     * untracked `vendor` directory is the reason that decision does not belong
     * here. Everything that needs git is asked in as few processes as the
     * command line allows, because a per-file `git diff` is slower than the
     * entire analysis it feeds.
     *
     * @param  list<ChangedFile>  $files
     * @return list<ChangedFile>
     */
    public function withHunks(string $base, array $files): array
    {
        return $this->diff()->withHunks($base, $files);
    }

    /**
     * Changed line ranges for many files, batched into as few git processes as
     * the platform's command-line limit allows.
     *
     * @param  list<string>  $relativePaths
     * @param  array<string, string>  $previousPaths  New path => the path it was renamed from.
     * @return array<string, list<DiffHunk>>
     */
    public function hunksForFiles(string $base, array $relativePaths, array $previousPaths = []): array
    {
        return $this->diff()->hunksForFiles($base, $relativePaths, $previousPaths);
    }

    /**
     * How many of the last `$commits` commits touched each file, with paths
     * relative to the working directory and limited to what lies beneath it.
     *
     * A project analysed from a subdirectory of a larger repository sees its
     * own files under the names it uses for them, and nothing outside it.
     *
     * @return array<string, int>
     */
    public function churn(int $commits): array
    {
        $output = $this->attempt(['log', '-n', (string) $commits, '--name-only', '--no-renames', '--relative', '-z', '--format=']);

        return $output === null ? [] : (new GitOutputParser)->pathCounts($output);
    }

    /**
     * @return list<string>
     */
    public function untrackedFiles(): array
    {
        $output = $this->attempt(['ls-files', '-z', '--others', '--exclude-standard']);

        return $output === null ? [] : (new GitOutputParser)->paths($output);
    }

    private function diff(): WorkingTreeDiff
    {
        return new WorkingTreeDiff($this, $this->workingDirectory);
    }
}
