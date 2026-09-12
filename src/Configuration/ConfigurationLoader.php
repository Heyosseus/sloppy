<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Configuration;

use RuntimeException;

/**
 * A {@see Configuration} for a project with no service container.
 *
 * Laravel merges `config/sloppy.php` over the package defaults for us. Outside
 * Laravel nothing does, so this looks in the places a project might keep its
 * configuration, falls back to the shipped defaults, and says which it used --
 * a run whose configuration came from somewhere unexpected should be able to
 * tell you where.
 */
final class ConfigurationLoader
{
    /**
     * In priority order, relative to the project root.
     *
     * `config/sloppy.php` comes first so a Laravel project analysed through the
     * binary behaves the same as through Artisan.
     *
     * @var list<string>
     */
    private const array CANDIDATES = [
        'config/sloppy.php',
        'sloppy.php',
        '.sloppy.php',
    ];

    private string $source = 'package defaults';

    public function __construct(private readonly string $basePath) {}

    public function load(?string $configPath = null): Configuration
    {
        $values = $this->values($configPath);
        $config = Configuration::fromArray($values, $this->basePath);

        return $this->withUsablePaths($config, $values);
    }

    /**
     * Where the configuration came from, for the command to print.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(?string $configPath): array
    {
        if ($configPath !== null && trim($configPath) !== '') {
            return $this->read(trim($configPath), trim($configPath));
        }

        foreach (self::CANDIDATES as $candidate) {
            $path = $this->basePath.'/'.$candidate;

            if (is_file($path)) {
                return $this->read($path, $candidate);
            }
        }

        return $this->read(dirname(__DIR__, 2).'/config/sloppy.php', 'package defaults');
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Configuration file [%s] does not exist.', $path));
        }

        /** @var mixed $values */
        $values = require $path;

        if (! is_array($values)) {
            throw new RuntimeException(sprintf('Configuration file [%s] must return an array.', $path));
        }

        $this->source = $label;

        /** @var array<string, mixed> $values */
        return $values;
    }

    /**
     * The shipped default path is `app`, which is a Laravel convention. When it
     * is not there and the project did not ask for anything else, the PSR-4
     * autoload roots are a better answer than analysing nothing.
     *
     * @param  array<string, mixed>  $values
     */
    private function withUsablePaths(Configuration $config, array $values): Configuration
    {
        if (isset($values['paths']) && $this->source !== 'package defaults') {
            return $config;
        }

        foreach ($config->paths() as $path) {
            if (is_dir($this->basePath.'/'.$path)) {
                return $config;
            }
        }

        $roots = $this->autoloadRoots();

        return $roots === [] ? $config : $config->withPaths($roots);
    }

    /**
     * PSR-4 source roots from the project's `composer.json` that actually
     * exist on disk.
     *
     * @return list<string>
     */
    private function autoloadRoots(): array
    {
        $roots = [];

        foreach ((new ComposerJson($this->basePath))->psr4Roots() as $root) {
            if (is_dir($this->basePath.'/'.$root)) {
                $roots[] = $root;
            }
        }

        return $roots;
    }
}
