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
    private ConfigReader $values;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private array $config,
        public string $basePath,
    ) {
        $this->values = new ConfigReader($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config, string $basePath): self
    {
        return new self($config, rtrim(str_replace('\\', '/', $basePath), '/'));
    }

    public function enabled(): bool
    {
        return $this->values->bool('enabled', true);
    }

    /**
     * Directories and files to analyse, relative to the project root.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = $this->values->stringList('paths');

        return $paths === [] ? ['app'] : $paths;
    }

    /**
     * Path fragments that exclude a file from analysis.
     *
     * @return list<string>
     */
    public function exclude(): array
    {
        return $this->values->stringList('exclude');
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
        return max(0, min(100, $this->values->int('min_confidence', 0)));
    }

    /**
     * Absolute path of the baseline file.
     */
    public function baselinePath(): string
    {
        $path = $this->values->string('baseline', '.sloppy-baseline.json');

        if ($this->isAbsolute($path)) {
            return $path;
        }

        return $this->basePath.'/'.ltrim($path, '/');
    }

    public function score(): ScoreConfiguration
    {
        return ScoreConfiguration::fromArray($this->values->arrayValue('score'));
    }

    /**
     * Weights for the attention model, which is a separate question from the
     * score and so a separate configuration key.
     */
    public function risk(): RiskConfiguration
    {
        return RiskConfiguration::fromArray($this->values->arrayValue('risk'));
    }

    /**
     * Which framework's rules apply: an explicit name, or `auto` to read the
     * answer off the project's `composer.json`.
     */
    public function framework(): string
    {
        $value = $this->config['framework'] ?? 'auto';

        return is_string($value) && trim($value) !== '' ? mb_strtolower(trim($value)) : 'auto';
    }

    /**
     * Whether a framework's rules should run for this project.
     */
    public function hasFramework(string $framework): bool
    {
        $configured = $this->framework();

        if ($configured === 'none') {
            return false;
        }

        if ($configured !== 'auto') {
            return $configured === mb_strtolower($framework);
        }

        return (new FrameworkDetector($this->basePath))->has($framework);
    }

    /**
     * Extra rule classes registered by the host application.
     *
     * @return list<class-string<Rule>>
     */
    public function customRules(): array
    {
        $classes = [];

        foreach ($this->values->stringList('custom_rules') as $class) {
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
        $rules = $this->values->arrayValue('rules');

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

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
