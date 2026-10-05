<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Ci\BaseRevision;
use Heyosseus\Sloppy\Ci\CiEnvironment;
use Heyosseus\Sloppy\Ci\CiProvider;
use Heyosseus\Sloppy\Git\Git;
use Heyosseus\Sloppy\Sloppy;

/**
 * Which revision a pipeline compares against, or that it should not compare
 * at all and analyse the whole project instead.
 *
 * Each way of ending up with the whole project says why on the console, so a
 * job that was expected to review a diff and did not is not a mystery.
 */
final readonly class CiBaseResolver
{
    public function __construct(private CiEnvironment $environment = new CiEnvironment) {}

    /**
     * The revision to compare against, or null to analyse the whole project.
     */
    public function resolve(Sloppy $sloppy, CiOptions $options, CiProvider $provider, RunnerOutput $output): ?string
    {
        if ($options->scan) {
            return null;
        }

        if ($options->base === null && $provider === CiProvider::GitLab) {
            $output->notice('Scanning the whole project: GitLab compares Code Quality reports itself.');

            return null;
        }

        $git = $sloppy->git();

        if (! $git->isAvailable() || ! $git->isRepository()) {
            $output->notice('Scanning the whole project: this is not a git checkout.');

            return null;
        }

        $base = BaseRevision::resolve($git, $options->base, $this->environment);

        if ($base === null) {
            $output->notice(sprintf(
                'Scanning the whole project: none of [%s] resolve here.',
                implode(', ', BaseRevision::candidates($options->base, $this->environment)) ?: 'no candidate',
            ));

            return null;
        }

        return $options->mergeBase ? $this->forkPoint($git, $base, $output) : $base;
    }

    /**
     * Where this change forked from a branch, rather than the branch's tip.
     *
     * Comparing with the tip charges the change with everything merged into
     * the target branch since it forked: every finding the target gained
     * shows up as one this change resolved, and every one it fixed as one
     * this change introduced. A commit spelled out by hash is taken at its
     * word.
     */
    private function forkPoint(Git $git, string $base, RunnerOutput $output): string
    {
        if (! $git->isRef($base)) {
            return $base;
        }

        $mergeBase = $git->mergeBase($base);

        if ($mergeBase === null) {
            $output->warn(sprintf(
                'No merge base between %s and HEAD -- a shallow clone needs fetch-depth: 0 -- so comparing with its tip.',
                $base,
            ));

            return $base;
        }

        if ($mergeBase === $git->commitOf($base)) {
            return $base;
        }

        $output->notice(sprintf('Comparing against the merge base of %s and HEAD: %s.', $base, substr($mergeBase, 0, 12)));

        return $mergeBase;
    }
}
