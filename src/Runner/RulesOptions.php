<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Agent\RulesetFormat;

/**
 * What `sloppy rules` was asked to generate.
 */
final readonly class RulesOptions
{
    /**
     * @param  list<RulesetFormat>  $formats  One file per format; empty means Boost's guidelines where Boost is installed, and Claude, the most common by a distance, everywhere else.
     * @param  string|null  $output  Write here instead of the format's usual file. Only meaningful for a single format.
     */
    public function __construct(
        public array $formats = [],
        public ?string $output = null,
        public bool $stdout = false,
        public bool $force = false,
    ) {}

    /**
     * @param  bool  $usesBoost  Whether the project has Laravel Boost, which owns the agent files there.
     * @return list<RulesetFormat>
     */
    public function formats(bool $usesBoost = false): array
    {
        if ($this->formats !== []) {
            return $this->formats;
        }

        return [$usesBoost ? RulesetFormat::Boost : RulesetFormat::Claude];
    }

    /**
     * @param  list<string>  $values
     * @return list<RulesetFormat>
     */
    public static function parseFormats(array $values): array
    {
        return array_values(array_unique(array_map(
            RulesetFormat::parse(...),
            $values,
        ), SORT_REGULAR));
    }
}
