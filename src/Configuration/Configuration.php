<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Configuration;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Contracts\Rule;
use InvalidArgumentException;

/**
 * Typed access to `config/sloppy.php`.
 *
 * Laravel's config repository is the single configuration mechanism -- there is
 * no competing YAML file to keep in sync. Everything reaching the analyser
 * passes through here, so a malformed value fails loudly and once.
 */
final readonly class Configuration
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private array $config,
        public string $basePath,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config, string $basePath): self
    {
        return new self($config, rtrim(str_replace('\\', '/', $basePath), '/'));
    }

    public function enabled(): bool
    {
        return $this->bool('enabled', true);
    }

    /**
     * Directories and files to analyse, relative to the project root.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = $this->stringList('paths');

        return $paths === [] ? ['app'] : $paths;
    }

    /**
     * Path fragments that exclude a file from analysis.
     *
     * @return list<string>
     */
    public function exclude(): array
    {
        return $this->stringList('exclude');
    }

    /**
     * Lowest severity that should fail the command, or null to never fail on
     * findings alone.
     */
    public function failOn(): ?Severity
    {
        $value = $this->config['fail_on'] ?? null;

        if (in_array($value, [null, false, '', 'never'], true)) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('sloppy.fail_on must be a severity name or null.');
        }

        return Severity::parse($value);
    }

    /**
     * Findings below this confidence are dropped before reporting. Raising it
     * is the quickest way to quiet a noisy run.
     */
    public function minConfidence(): int
    {
        return max(0, min(100, $this->int('min_confidence', 0)));
    }

    /**
     * Absolute path of the baseline file.
     */
    public function baselinePath(): string
    {
        $path = $this->string('baseline', '.sloppy-baseline.json');

        if ($this->isAbsolute($path)) {
            return $path;
        }

        return $this->basePath.'/'.ltrim($path, '/');
    }

    public function score(): ScoreConfiguration
    {
        return ScoreConfiguration::fromArray($this->arrayValue('score'));
    }

    /**
     * Extra rule classes registered by the host application.
     *
     * @return list<class-string<Rule>>
     */
    public function customRules(): array
    {
        $classes = [];

        foreach ($this->stringList('custom_rules') as $class) {
            if (! is_subclass_of($class, Rule::class)) {
                throw new InvalidArgumentException(sprintf(
                    'Custom rule [%s] must implement %s.',
                    $class,
                    Rule::class,
                ));
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * Per-rule options, e.g. `['max_lines' => 80]`.
     *
     * @return array<string, mixed>
     */
    public function ruleOptions(string $id): array
    {
        $rules = $this->arrayValue('rules');

        $options = $rules[$id] ?? null;

        if (! is_array($options)) {
            return [];
        }

        /** @var array<string, mixed> $filtered */
        $filtered = [];

        /** @var mixed $value */
        foreach ($options as $key => $value) {
            if (is_string($key)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Rules are on unless the configuration says otherwise, so a new rule in a
     * new release starts working without anyone editing their config.
     */
    public function isRuleEnabled(string $id): bool
    {
        /** @var mixed $enabled */
        $enabled = $this->ruleOptions($id)['enabled'] ?? true;

        return $enabled !== false;
    }

    /**
     * @param  list<string>  $paths
     */
    public function withPaths(array $paths): self
    {
        $config = $this->config;
        $config['paths'] = $paths;

        return new self($config, $this->basePath);
    }

    public function withFailOn(?Severity $severity): self
    {
        $config = $this->config;
        $config['fail_on'] = $severity?->value;

        return new self($config, $this->basePath);
    }

    public function withMinConfidence(int $confidence): self
    {
        $config = $this->config;
        $config['min_confidence'] = $confidence;

        return new self($config, $this->basePath);
    }

    // -----------------------------------------------------------------
    // Primitive readers
    // -----------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $values = [];

        /** @var mixed $value */
        foreach ($this->arrayValue($key) as $value) {
            if (is_string($value) && trim($value) !== '') {
                $values[] = trim($value);
            }
        }

        return $values;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayValue(string $key): array
    {
        /** @var mixed $value */
        $value = $this->config[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function int(string $key, int $default): int
    {
        $value = $this->config[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->config[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
