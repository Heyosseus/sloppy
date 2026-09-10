<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands\Concerns;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Sloppy;
use InvalidArgumentException;

/**
 * Turns command-line options into a {@see Configuration}.
 *
 * Every command accepts the same overrides -- paths, threshold, confidence
 * floor -- and each of them means the same thing everywhere, so the translation
 * happens once.
 */
trait ResolvesConfiguration
{
    protected function resolveSloppy(Sloppy $sloppy): Sloppy
    {
        $configuration = $sloppy->configuration;
        $paths = $this->stringListOption('path');

        if ($paths !== []) {
            $configuration = $configuration->withPaths($paths);
        }

        $failOn = $this->optionIfDeclared('fail-on');

        if (is_string($failOn) && trim($failOn) !== '') {
            $configuration = $configuration->withFailOn($this->parseThreshold($failOn));
        }

        $minConfidence = $this->optionIfDeclared('min-confidence');

        if (is_string($minConfidence) && trim($minConfidence) !== '') {
            if (! is_numeric($minConfidence)) {
                throw new InvalidArgumentException('--min-confidence must be a number between 0 and 100.');
            }

            $configuration = $configuration->withMinConfidence((int) $minConfidence);
        }

        $resolved = $sloppy->withConfiguration($configuration);
        $rules = $this->stringListOption('rule');

        return $rules === [] ? $resolved : $resolved->onlyRules($rules);
    }

    /**
     * Not every command declares every shared option, and asking Symfony for
     * one that is not declared throws.
     */
    protected function optionIfDeclared(string $name): mixed
    {
        return $this->getDefinition()->hasOption($name) ? $this->option($name) : null;
    }

    /**
     * @return list<string>
     */
    protected function stringListOption(string $name): array
    {
        $value = $this->optionIfDeclared($name);

        if (! is_array($value)) {
            return [];
        }

        $items = [];

        /** @var mixed $item */
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return $items;
    }

    /**
     * `--fail-on=never` disables the gate; anything else must be a severity.
     */
    protected function parseThreshold(string $value): ?Severity
    {
        $normalised = mb_strtolower(trim($value));

        return in_array($normalised, ['never', 'none', 'off'], true)
            ? null
            : Severity::parse($normalised);
    }

    protected function outputFormat(): string
    {
        $format = $this->optionIfDeclared('format');
        $normalised = is_string($format) ? mb_strtolower(trim($format)) : 'console';

        if (! in_array($normalised, ['console', 'json'], true)) {
            throw new InvalidArgumentException(sprintf('Unknown --format [%s]. Expected console or json.', $normalised));
        }

        return $normalised;
    }

    protected function thresholdFor(Configuration $configuration): ?Severity
    {
        return $configuration->failOn();
    }
}
