<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

/**
 * `--path` values typed in a subdirectory of the project, read the way the
 * shell would read them.
 *
 * The rest of the package takes a relative path relative to the project root.
 * Someone who `cd`s into `app/` and runs `sloppy --path=.` means `app/`, not
 * the whole project -- and when the nearest `composer.json` is far above them,
 * not a directory tree they never meant to scan. A relative path that names
 * something from the working directory is therefore rewritten relative to the
 * root; one that does not is left alone, so a root-relative path typed from a
 * subdirectory still works as it did.
 */
final readonly class WorkingDirectoryPaths
{
    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function resolve(array $paths, string $root, string $cwd): array
    {
        $root = $this->normalise($root);
        $cwdReal = realpath($cwd);

        if ($cwdReal === false || $this->same($this->normalise($cwdReal), $root)) {
            return $paths;
        }

        return array_map(fn (string $path): string => $this->resolveOne($path, $root, $cwdReal), $paths);
    }

    private function resolveOne(string $path, string $root, string $cwd): string
    {
        if ($this->isAbsolute($path)) {
            return $path;
        }

        $real = realpath($cwd.DIRECTORY_SEPARATOR.$path);

        if ($real === false) {
            return $path;
        }

        $resolved = $this->normalise($real);

        if ($this->startsWith($resolved, $root.'/')) {
            return substr($resolved, strlen($root) + 1);
        }

        return $resolved;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function same(string $a, string $b): bool
    {
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    private function startsWith(string $path, string $prefix): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? strncasecmp($path, $prefix, strlen($prefix)) === 0
            : str_starts_with($path, $prefix);
    }
}
