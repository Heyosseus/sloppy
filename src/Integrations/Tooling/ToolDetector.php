<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Integrations\Tooling;

/**
 * Whether the project already has Pint and Rector, and where.
 *
 * Sloppy does not install them and does not ask the user to: a project that
 * has them gets `sloppy fix`, and one that does not gets told which one is
 * missing. Composer's binary directory is configurable, so `vendor/bin` is a
 * default rather than an assumption, and Windows gets the `.bat` shim Composer
 * writes beside the PHP script.
 */
final readonly class ToolDetector
{
    public function __construct(
        private string $basePath,
        private string $binDirectory = 'vendor/bin',
    ) {}

    public function pint(): ?string
    {
        return $this->find('pint');
    }

    public function rector(): ?string
    {
        return $this->find('rector');
    }

    public function hasPint(): bool
    {
        return $this->pint() !== null;
    }

    public function hasRector(): bool
    {
        return $this->rector() !== null;
    }

    /**
     * The binary's absolute path, or null when the project does not have it.
     */
    public function find(string $tool): ?string
    {
        $directory = rtrim(str_replace('\\', '/', $this->basePath), '/').'/'.trim($this->binDirectory, '/');

        foreach ([$tool.'.bat', $tool] as $candidate) {
            $path = $directory.'/'.$candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
