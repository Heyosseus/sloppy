<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Configuration;

/**
 * A project's `composer.json`, read once and asked the two questions the rest
 * of the package needs from it.
 *
 * {@see FrameworkDetector} wants to know what a project requires;
 * {@see ConfigurationLoader} wants to know where its PSR-4 autoloader points.
 * Both begin with the same read-file, decode, and validate-it-is-an-array
 * prologue, so that part lives here once rather than twice.
 *
 * A missing or malformed file answers "nothing" to both questions rather than
 * throwing: a project whose `composer.json` cannot be read is still one we
 * should analyse, just without the answers that file would have given.
 */
final readonly class ComposerJson
{
    /**
     * @var array<string, mixed>
     */
    private array $decoded;

    public function __construct(string $basePath)
    {
        $this->decoded = $this->read($basePath.'/composer.json');
    }

    /**
     * Whether the project requires a package, in either `require` or
     * `require-dev`.
     */
    public function requires(string $package): bool
    {
        return isset($this->requirements()[mb_strtolower($package)]);
    }

    /**
     * `autoload.psr-4` source directories, deduplicated and sorted so two
     * reads of the same file agree.
     *
     * @return list<string>
     */
    public function psr4Roots(): array
    {
        $autoload = $this->decoded['autoload'] ?? null;
        $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;

        if (! is_array($psr4)) {
            return [];
        }

        $roots = [];

        /** @var mixed $target */
        foreach ($psr4 as $target) {
            foreach (is_array($target) ? $target : [$target] as $directory) {
                if (! is_string($directory)) {
                    continue;
                }

                $trimmed = trim(str_replace('\\', '/', $directory), '/');

                if ($trimmed !== '') {
                    $roots[$trimmed] = true;
                }
            }
        }

        $sorted = array_keys($roots);
        sort($sorted);

        return $sorted;
    }

    /**
     * Every package name required, in either section, lower-cased.
     *
     * @return array<string, true>
     */
    private function requirements(): array
    {
        $names = [];

        foreach (['require', 'require-dev'] as $section) {
            $packages = $this->decoded[$section] ?? null;

            if (! is_array($packages)) {
                continue;
            }

            foreach (array_keys($packages) as $name) {
                if (is_string($name)) {
                    $names[mb_strtolower($name)] = true;
                }
            }
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        $contents = is_readable($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
