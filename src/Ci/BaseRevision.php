<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ci;

use Heyosseus\Sloppy\Git\Git;

/**
 * Which revision a CI run should compare against.
 *
 * The answer is deliberately forgiving. `main` exists locally but not in a CI
 * checkout; `origin/main` exists in CI but not in a fresh clone; a push to the
 * default branch has no target branch at all. A tool that demanded the right
 * name would fail half the pipelines it was added to, so this tries the names
 * that could be meant, in the order they are most likely to be right, and says
 * plainly when none of them resolve.
 */
final readonly class BaseRevision
{
    /**
     * @param  string|null  $requested  What the user asked for, if anything.
     * @return string|null The first revision that exists, or null when none do.
     */
    public static function resolve(Git $git, ?string $requested, CiEnvironment $environment): ?string
    {
        foreach (self::candidates($requested, $environment) as $candidate) {
            if ($git->revisionExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function candidates(?string $requested, CiEnvironment $environment): array
    {
        if ($requested !== null && trim($requested) !== '') {
            $name = trim($requested);

            return array_values(array_unique([$name, 'origin/'.$name]));
        }

        return $environment->baseCandidates();
    }
}
