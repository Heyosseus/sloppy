<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

use Heyosseus\Sloppy\Support\StringListOption;

/**
 * Which directories hold routes and which hold migrations.
 *
 * Decided by path alone, so a file is the same kind in a scan, a diff, the
 * agent hooks and `sloppy watch`. Outside Laravel every file is code.
 */
final readonly class SourceKinds
{
    /**
     * Directories are matched as whole path segments at any depth, the way
     * `exclude` is, so `routes` also covers `Modules/Billing/routes`.
     *
     * @param  list<string>  $routes  Project-relative directories; `*` is a glob.
     * @param  list<string>  $migrations
     */
    public function __construct(
        public array $routes = [],
        public array $migrations = [],
    ) {}

    /**
     * Where a Laravel application keeps them unless it says otherwise. The
     * capitalised entries are module layouts such as Modules/Billing/Routes.
     */
    public static function laravel(): self
    {
        return new self(['routes', 'Routes'], ['database/migrations', 'Database/Migrations']);
    }

    /**
     * From `sloppy.laravel`: a key the project left out keeps its default.
     *
     * @param  array<array-key, mixed>  $settings
     */
    public static function fromSettings(array $settings): self
    {
        $defaults = self::laravel();

        return new self(
            routes: array_key_exists('routes', $settings) ? StringListOption::from($settings['routes']) : $defaults->routes,
            migrations: array_key_exists('migrations', $settings) ? StringListOption::from($settings['migrations']) : $defaults->migrations,
        );
    }

    public function of(string $relativePath): SourceKind
    {
        $path = ltrim(str_replace('\\', '/', $relativePath), '/');

        if ($this->under($path, $this->migrations)) {
            return SourceKind::Migration;
        }

        if ($this->under($path, $this->routes)) {
            return SourceKind::Routes;
        }

        return SourceKind::Code;
    }

    /**
     * Every directory named, routes first.
     *
     * @return list<string>
     */
    public function directories(): array
    {
        return array_values(array_unique([...$this->routes, ...$this->migrations]));
    }

    /**
     * @param  list<string>  $directories
     */
    private function under(string $path, array $directories): bool
    {
        foreach ($directories as $directory) {
            $root = trim(str_replace('\\', '/', $directory), '/');

            if ($root === '') {
                continue;
            }

            $matches = str_contains($root, '*')
                ? fnmatch($root.'/*', $path) || fnmatch('*/'.$root.'/*', $path)
                : str_starts_with($path, $root.'/') || str_contains($path, '/'.$root.'/');

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
