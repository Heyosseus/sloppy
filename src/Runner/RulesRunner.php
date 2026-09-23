<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Agent\RulesetFile;
use Heyosseus\Sloppy\Agent\RulesetFormat;
use Heyosseus\Sloppy\Agent\RulesetGenerator;
use Heyosseus\Sloppy\Configuration\ComposerJson;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Sloppy;

/**
 * Write this project's rules where the coding agents will read them.
 *
 * The cheapest finding is the one that never gets written. An agent that has
 * read the rules before it starts does not produce the god method, so the
 * review does not have to catch it and the author does not have to fix it.
 */
final readonly class RulesRunner
{
    public function run(Sloppy $sloppy, RulesOptions $options, RunnerOutput $output): ExitCode
    {
        if ($sloppy->rules()->count() === 0) {
            $output->error('No rules are enabled, so there is nothing to write. Check sloppy.rules.');

            return ExitCode::Error;
        }

        $generator = RulesetGenerator::for($sloppy);
        $failed = false;

        foreach ($options->formats($this->usesBoost($sloppy->configuration)) as $format) {
            $contents = $generator->generate($format);

            if ($options->stdout) {
                $output->report($contents, $format->isJson() ? OutputFormat::Json : OutputFormat::Markdown);

                continue;
            }

            $failed = ! $this->write($sloppy, $format, $contents, $options, $output) || $failed;
        }

        return $failed ? ExitCode::Error : ExitCode::Success;
    }

    /**
     * Boost regenerates `CLAUDE.md` and `AGENTS.md` and tells teams to ignore
     * them in git, so a block merged there is never committed. Where Boost is
     * installed, the rules belong in its guidelines instead.
     */
    private function usesBoost(Configuration $configuration): bool
    {
        return (new ComposerJson($configuration->basePath))->requires('laravel/boost')
            || is_file($configuration->absolutePath('boost.json'));
    }

    private function write(Sloppy $sloppy, RulesetFormat $format, string $contents, RulesOptions $options, RunnerOutput $output): bool
    {
        $relative = $options->output ?? $format->defaultFile();
        $path = $sloppy->configuration->absolutePath($relative);
        $existing = is_file($path) ? @file_get_contents($path) : false;

        if ($existing !== false && ! $options->force && ! $this->mayReplace($format, $existing)) {
            $output->error(sprintf('%s already exists. Pass --force to overwrite it.', $relative));

            return false;
        }

        $merged = $format->ownsFile()
            ? $contents
            : RulesetFile::merge($existing === false ? null : $existing, $contents);

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            $output->error(sprintf('Could not create %s.', $directory));

            return false;
        }

        if (@file_put_contents($path, $merged) === false) {
            $output->error(sprintf('Could not write %s.', $relative));

            return false;
        }

        $output->info(sprintf(
            '%s %s for %s (%d rule(s)).',
            $this->verb($format, $existing === false ? null : $existing),
            $relative,
            $format->label(),
            $sloppy->rules()->count(),
        ));

        if ($format === RulesetFormat::Boost) {
            $output->info('Run `php artisan boost:update` so Boost adds it to your agent files.');
        }

        return true;
    }

    /**
     * A merged format never replaces anything but its own block. A file we
     * own outright is ours to replace only if we wrote it: the Boost guideline
     * says so on its first line; a JSON file cannot, so it always asks.
     */
    private function mayReplace(RulesetFormat $format, string $existing): bool
    {
        return match ($format) {
            RulesetFormat::Json => false,
            RulesetFormat::Boost => str_starts_with($existing, RulesetGenerator::BOOST_MARKER),
            default => true,
        };
    }

    /**
     * What just happened to the file, said precisely: a team that keeps notes
     * in `CLAUDE.md` needs to know whether their notes are still there.
     */
    private function verb(RulesetFormat $format, ?string $existing): string
    {
        if ($existing === null) {
            return 'Created';
        }

        if ($format->ownsFile()) {
            return 'Replaced';
        }

        return RulesetFile::hasBlock($existing) ? 'Refreshed the Sloppy block in' : 'Updated';
    }
}
