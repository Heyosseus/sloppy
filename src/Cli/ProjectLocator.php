<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use RuntimeException;

/**
 * Which directory the standalone binary is being asked to analyse.
 *
 * `composer.json` marks the root, because it is the one file every PHP project
 * has and the file the framework detector and the PSR-4 fallback both read.
 */
final readonly class ProjectLocator
{
    /**
     * How far up from the working directory to look before giving up.
     */
    private const int MAX_DEPTH = 12;

    /**
     * @param  string|null  $given  An explicit project directory; a relative one is taken relative to `$cwd`.
     */
    public function locate(?string $given, string $cwd): string
    {
        if ($given !== null && trim($given) !== '') {
            // Relative to the directory the caller works from, not to wherever
            // this process happens to have been started: an MCP server asked
            // about "packages/billing" means the one under its own root.
            $resolved = realpath($this->isAbsolute(trim($given)) ? trim($given) : $cwd.DIRECTORY_SEPARATOR.trim($given));

            if ($resolved === false || ! is_dir($resolved)) {
                throw new RuntimeException(sprintf('Project directory [%s] does not exist.', trim($given)));
            }

            return $this->normalise($resolved);
        }

        $directory = realpath($cwd);

        if ($directory === false) {
            throw new RuntimeException(sprintf('Working directory [%s] could not be resolved.', $cwd));
        }

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            if (is_file($directory.DIRECTORY_SEPARATOR.'composer.json')) {
                return $this->normalise($directory);
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        throw new RuntimeException(sprintf(
            'No composer.json found in %s or any parent directory. Pass --project to say where the project is.',
            $this->normalise((string) realpath($cwd)),
        ));
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_contains($path, '://');
    }

    /**
     * Forward slashes throughout, matching how the rest of the package stores
     * paths.
     */
    private function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
