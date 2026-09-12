<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Configuration;

/**
 * Which frameworks the analysed project actually uses.
 *
 * The answer comes from the project's own `composer.json` rather than from its
 * directory layout, because a directory called `app` proves nothing and
 * guessing wrong silently disables ten rules.
 */
final readonly class FrameworkDetector
{
    /**
     * Packages whose presence is evidence of a framework. `illuminate/*` counts
     * because a Laravel *package* depends on those rather than on
     * `laravel/framework`, and its code is still Laravel code.
     *
     * @var array<string, list<string>>
     */
    private const array EVIDENCE = [
        'laravel' => [
            'laravel/framework',
            'illuminate/support',
            'illuminate/database',
            'illuminate/contracts',
        ],
    ];

    private ComposerJson $composer;

    public function __construct(string $basePath)
    {
        $this->composer = new ComposerJson($basePath);
    }

    /**
     * @return list<string>
     */
    public function detect(): array
    {
        $detected = [];

        foreach (self::EVIDENCE as $framework => $packages) {
            foreach ($packages as $package) {
                if ($this->composer->requires($package)) {
                    $detected[] = $framework;

                    break;
                }
            }
        }

        sort($detected);

        return $detected;
    }

    public function has(string $framework): bool
    {
        return in_array($framework, $this->detect(), true);
    }
}
