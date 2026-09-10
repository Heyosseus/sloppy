<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Support;

use Heyosseus\Sloppy\Configuration\Configuration;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Resolves the configured paths into the list of PHP files to analyse.
 *
 * Results are sorted, so two runs on the same tree analyse files in the same
 * order and produce identical reports.
 */
final readonly class FileFinder
{
    public function __construct(private Configuration $config) {}

    /**
     * @return list<string> Absolute paths, sorted.
     */
    public function find(): array
    {
        return $this->findIn($this->config->paths());
    }

    /**
     * @param  list<string>  $paths  Relative or absolute.
     * @return list<string>
     */
    public function findIn(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = $this->absolute($path);

            if (is_file($absolute)) {
                if (str_ends_with($absolute, '.php') && ! $this->isExcluded($this->relative($absolute))) {
                    $files[$absolute] = true;
                }

                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            foreach ($this->scan($absolute) as $file) {
                $files[$file] = true;
            }
        }

        $sorted = array_keys($files);
        sort($sorted);

        return $sorted;
    }

    /**
     * Whether a relative path matches any configured exclusion.
     *
     * Exclusions are matched as whole path segments at any depth, so `vendor`
     * excludes both `vendor/...` and `packages/foo/vendor/...` without anyone
     * writing a glob. Patterns containing `*` are matched as globs instead.
     */
    public function isExcluded(string $relativePath): bool
    {
        $path = str_replace('\\', '/', $relativePath);

        foreach ($this->config->exclude() as $pattern) {
            $needle = trim(str_replace('\\', '/', $pattern), '/');

            if ($needle === '') {
                continue;
            }

            if (str_contains($needle, '*')) {
                if (fnmatch($needle, $path) || fnmatch($needle.'/*', $path) || fnmatch('*/'.$needle, $path)) {
                    return true;
                }

                continue;
            }

            if ($path === $needle || str_starts_with($path, $needle.'/') || str_contains($path, '/'.$needle.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Project-relative path with forward slashes, which is what findings,
     * baselines and reports use everywhere.
     */
    public function relative(string $absolutePath): string
    {
        $normalized = str_replace('\\', '/', $absolutePath);
        $base = $this->config->basePath;

        if ($base !== '' && str_starts_with($normalized, $base.'/')) {
            return substr($normalized, strlen($base) + 1);
        }

        return ltrim($normalized, '/');
    }

    public function absolute(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            return $normalized;
        }

        return $this->config->basePath.'/'.ltrim($normalized, '/');
    }

    /**
     * @return list<string>
     */
    private function scan(string $directory): array
    {
        $finder = (new Finder)
            ->files()
            ->in($directory)
            ->name('*.php')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true);

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            $absolute = str_replace('\\', '/', $file->getPathname());

            if ($this->isExcluded($this->relative($absolute))) {
                continue;
            }

            $files[] = $absolute;
        }

        return $files;
    }
}
