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

    public function locate(?string $given, string $cwd): string
    {
        if ($given !== null && trim($given) !== '') {
            $resolved = realpath(trim($given));

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

    /**
     * Forward slashes throughout, matching how the rest of the package stores
     * paths.
     */
    private function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
