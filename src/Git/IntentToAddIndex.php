<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Git;

/**
 * A throwaway copy of the index in which untracked paths are marked as
 * intended to be added.
 *
 * A diff against a revision only sees paths git knows about, so a file moved
 * with a plain `mv` -- its old path deleted, its new one untracked -- looks
 * like a deletion and an unrelated new file. Marking the new path in a copy
 * lets git pair the two, and the user's own index is never written.
 */
final readonly class IntentToAddIndex
{
    public function __construct(
        private Git $git,
        private string $workingDirectory,
        private GitOutputParser $parser = new GitOutputParser,
    ) {}

    /**
     * Run something against a copy of the index in which these untracked
     * paths are marked as intended to be added, so a diff against a revision
     * sees them and can pair them with the paths they were moved from.
     *
     * @param  list<string>  $paths
     * @param  callable(array<string, string>): void  $run  Handed the environment to run git with.
     */
    public function with(array $paths, callable $run): void
    {
        if ($paths === []) {
            $run([]);

            return;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'sloppy-index-');

        if ($temporary === false) {
            $run([]);

            return;
        }

        $index = $this->indexPath();

        if ($index !== null && is_file($index)) {
            copy($index, $temporary);
        } else {
            // An empty file is not an index; no file at all is an empty one.
            @unlink($temporary);
        }

        $env = ['GIT_INDEX_FILE' => $temporary];

        try {
            foreach ($this->parser->batches($paths) as $batch) {
                $this->git->attempt(['add', '--intent-to-add', '--', ...$batch], $env);
            }

            $run($env);
        } finally {
            @unlink($temporary);
            @unlink($temporary.'.lock');
        }
    }

    private function indexPath(): ?string
    {
        $path = $this->git->attempt(['rev-parse', '--git-path', 'index']);

        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = str_replace('\\', '/', trim($path));
        $isAbsolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;

        return $isAbsolute ? $path : $this->workingDirectory.'/'.$path;
    }
}
