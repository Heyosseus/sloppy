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
     * @param  list<RulesetFormat>  $formats  One file per format; empty means Claude, the most common by a distance.
     * @param  string|null  $output  Write here instead of the format's usual file. Only meaningful for a single format.
     */
    public function __construct(
        public array $formats = [],
        public ?string $output = null,
        public bool $stdout = false,
        public bool $force = false,
    ) {}

    /**
     * @return list<RulesetFormat>
     */
    public function formats(): array
    {
        return $this->formats === [] ? [RulesetFormat::Claude] : $this->formats;
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
