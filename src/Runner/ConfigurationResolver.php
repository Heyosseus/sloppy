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
    public function resolve(Sloppy $sloppy, RunnerOptions $options, ?RunnerOutput $output = null): Sloppy
    {
        $configuration = $sloppy->configuration;

        foreach ($configuration->unknownSettings as $unknown) {
            $output?->warn($unknown.' It is ignored.');
        }

        // A misspelt key or a value of the wrong type used to be replaced by
        // its default without a word; a run must not analyse something other
        // than what its configuration says.
        // Every problem is named at once, so a configuration is fixed in one pass.
        if ($configuration->problems !== []) {
            throw new InvalidArgumentException(sprintf(
                'The sloppy configuration is invalid:
  - %s',
                implode('
  - ', $configuration->problems),
            ));
        }

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
     * Every `--path` given on the command line must exist.
     *
     * A typo in a path used to analyse nothing, warn about it and exit 0 --
     * in a pipeline, a green check that checked nothing.
     *
     * @throws InvalidArgumentException
     */
    public function assertPathsExist(Sloppy $sloppy, RunnerOptions $options): void
    {
        $missing = [];

        foreach ($options->paths() as $path) {
            if (! file_exists($sloppy->configuration->absolutePath($path))) {
                $missing[] = $path;
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Path [%s] does not exist in %s.',
                implode('], [', $missing),
                $sloppy->configuration->basePath,
            ));
        }
    }

    /**
     * Whether the run's parse errors cover every file it was meant to read:
     * a project with nothing analysed has no score, and must not be handed
     * a clean one.
     *
     * @param  array<string, string>  $errors  Keyed by relative path.
     */
    public function nothingParsed(Sloppy $sloppy, array $errors): bool
    {
        if ($errors === []) {
            return false;
        }

        $files = $sloppy->fileMap();

        return $files !== [] && array_diff_key($files, $errors) === [];
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
