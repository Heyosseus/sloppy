<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ci;

/**
 * What the surrounding CI system already knows, read once.
 *
 * The point of `sloppy ci` is that three lines of YAML are enough, and that is
 * only true if the command works out for itself which branch a pull request
 * targets, where the job summary is written and how results are handed back to
 * the workflow. Every one of those answers is an environment variable, and
 * they are all read here so nothing else in the package has to know the names.
 *
 * The variables are injected rather than read from `getenv()` so tests can
 * describe a pull request without being run inside one.
 */
final readonly class CiEnvironment
{
    /**
     * @param  array<string, string>  $variables
     */
    public function __construct(private array $variables = []) {}

    /**
     * The real environment, including variables set by `putenv()`.
     */
    public static function fromGlobals(): self
    {
        /** @var array<string, string> $variables */
        $variables = getenv();

        return new self($variables);
    }

    public function provider(): CiProvider
    {
        if ($this->value('GITHUB_ACTIONS') !== null) {
            return CiProvider::GitHubActions;
        }

        return $this->value('GITLAB_CI') !== null ? CiProvider::GitLab : CiProvider::Unknown;
    }

    /**
     * Whether this looks like an automated run at all.
     *
     * `CI` is set by practically every provider, so a run under Jenkins or
     * Buildkite is still recognised as CI even though the provider is not.
     */
    public function isCi(): bool
    {
        return $this->provider() !== CiProvider::Unknown || $this->value('CI') !== null;
    }

    /**
     * Whether this run is reviewing a proposed change rather than a branch.
     */
    public function isProposedChange(): bool
    {
        if ($this->value('GITHUB_BASE_REF') !== null) {
            return true;
        }

        return $this->value('CI_MERGE_REQUEST_TARGET_BRANCH_NAME') !== null;
    }

    /**
     * Revisions that could be the base of the comparison, best first.
     *
     * A checkout in CI is usually a detached HEAD with only the remote
     * branches present, so `origin/main` is tried before `main`. The default
     * branch comes last: on a push to a feature branch there is no target
     * branch to read, and comparing against the default branch is still the
     * question worth answering.
     *
     * @return list<string>
     */
    public function baseCandidates(): array
    {
        $names = array_filter([
            $this->value('GITHUB_BASE_REF'),
            $this->value('CI_MERGE_REQUEST_TARGET_BRANCH_NAME'),
            $this->value('CI_DEFAULT_BRANCH'),
        ], static fn (?string $name): bool => $name !== null);

        $candidates = [];

        foreach ($names as $name) {
            $candidates[] = 'origin/'.$name;
            $candidates[] = $name;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * The file GitHub renders under the job, if we are in a job that has one.
     */
    public function summaryPath(): ?string
    {
        return $this->value('GITHUB_STEP_SUMMARY');
    }

    /**
     * The file a composite action reads step outputs back from.
     */
    public function outputPath(): ?string
    {
        return $this->value('GITHUB_OUTPUT');
    }

    public function value(string $name): ?string
    {
        $value = $this->variables[$name] ?? null;

        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
