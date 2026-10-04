<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Architecture\ProfileInference;
use Heyosseus\Sloppy\Architecture\ProfileProposal;
use Heyosseus\Sloppy\Architecture\Role;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Sloppy;

/**
 * Shows a proposed architecture, settles what it could not decide by asking,
 * and writes it to `sloppy-architecture.php` -- or prints it for a pipe.
 *
 * Nothing is written without a yes from a person at the terminal or an
 * explicit `--write`, and an existing file is replaced only with `--force`.
 */
final readonly class ProposalWriter
{
    /**
     * Questions asked about namespaces `init` could not place, at most.
     */
    private const int QUESTIONS = 5;

    public function write(Sloppy $sloppy, ProfileProposal $proposal, ArchitectureOptions $options, RunnerOutput $output, string $by): ExitCode
    {
        if ($options->format === OutputFormat::Json) {
            $proposal->validate();
            $output->report((new ArchitectureReport)->json([...$proposal->toArray(), 'php' => $proposal->php($by)]), OutputFormat::Json);

            return ExitCode::Success;
        }

        if ($output->canAsk()) {
            $proposal = $this->settle($proposal, $output);
        }

        $proposal->validate();
        $output->report($proposal->php($by), OutputFormat::Markdown);

        return $this->save($sloppy, $proposal->php($by), $options, $output);
    }

    private function save(Sloppy $sloppy, string $php, ArchitectureOptions $options, RunnerOutput $output): ExitCode
    {
        $path = $sloppy->configuration->basePath.'/'.Profile::FILE;

        // A file written next to an architecture sloppy.php declares would
        // contradict it, and the next run would refuse both.
        if ($sloppy->configuration->declaresArchitecture()) {
            $output->warn(sprintf('sloppy.architecture already declares an architecture, so %s was not written. Merge the proposal into it, or remove it there and run this again.', Profile::FILE));

            return ExitCode::Success;
        }

        if (! $options->write && ! $output->canAsk()) {
            $output->notice(sprintf('Nothing was written. Pass --write to save this as %s.', Profile::FILE));

            return ExitCode::Success;
        }

        if (is_file($path) && ! $options->force) {
            $output->error(sprintf('%s already exists. Pass --force to replace it.', Profile::FILE));

            return ExitCode::Error;
        }

        if (! $options->write && ! $output->confirm(sprintf('Write this to %s?', Profile::FILE))) {
            $output->info('Nothing was written.');

            return ExitCode::Success;
        }

        // The warning PHP raises says less than the error below, which names
        // the file; the return value is what decides.
        if (@file_put_contents($path, $php) === false) {
            $output->error(sprintf('Could not write %s.', $path));

            return ExitCode::Error;
        }

        $output->info(sprintf('Wrote %s. Run `sloppy architecture` to check every class\'s role, then commit it.', Profile::FILE));

        return ExitCode::Success;
    }

    /**
     * Ask about each namespace that holds many classes and no role, rather
     * than guessing which ones the team considers a layer.
     */
    private function settle(ProfileProposal $proposal, RunnerOutput $output): ProfileProposal
    {
        foreach (array_slice($proposal->unclear, 0, self::QUESTIONS, true) as $namespace => $count) {
            $name = ProfileInference::roleName(substr((string) strrchr('\\'.$namespace, '\\'), 1));

            if ($proposal->validate()->role($name) instanceof Role || preg_match(Profile::ROLE_NAME, $name) !== 1) {
                continue;
            }

            if ($output->confirm(sprintf('%s holds %d classes with no role. Give them one of their own, "%s"?', $namespace, $count, $name))) {
                $proposal = $proposal->withRole(
                    $name,
                    ['description' => sprintf('Classes in %s.', $namespace), 'namespace' => $namespace.'\\*'],
                    sprintf('Role %s: the classes in %s, as asked.', $name, $namespace),
                );
            }
        }

        return $proposal;
    }
}
