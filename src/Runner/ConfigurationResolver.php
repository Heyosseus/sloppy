<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Sloppy;
use InvalidArgumentException;

/**
 * Turns command-line overrides into a configured {@see Sloppy}.
 *
 * Three commands accept the same overrides and each one means the same thing
 * everywhere, so the translation lives here rather than in each surface.
 */
final readonly class ConfigurationResolver
{
    public function resolve(Sloppy $sloppy, RunnerOptions $options): Sloppy
    {
        $configuration = $sloppy->configuration;

        if ($options->paths() !== []) {
            $configuration = $configuration->withPaths($options->paths());
        }

        $failOn = $options->failOn();

        if ($failOn !== null && trim($failOn) !== '') {
            $configuration = $configuration->withFailOn($this->threshold($failOn));
        }

        $confidence = $options->minConfidence();

        if ($confidence !== null) {
            if ($confidence < 0 || $confidence > 100) {
                throw new InvalidArgumentException('--min-confidence must be a number between 0 and 100.');
            }

            $configuration = $configuration->withMinConfidence($confidence);
        }

        $resolved = $sloppy->withConfiguration($configuration);

        return $options->rules() === [] ? $resolved : $resolved->onlyRules($options->rules());
    }

    /**
     * `--fail-on=never` disables the gate; anything else must be a severity.
     */
    private function threshold(string $value): ?Severity
    {
        $normalised = mb_strtolower(trim($value));

        return in_array($normalised, ['never', 'none', 'off'], true)
            ? null
            : Severity::parse($normalised);
    }
}
