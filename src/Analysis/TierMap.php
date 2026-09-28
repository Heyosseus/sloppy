<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

use Heyosseus\Sloppy\Configuration\Configuration;

/**
 * The tier each finding belongs to under one configuration.
 *
 * With no configuration every rule gets its category's default, which is what
 * a formatter built in a test, or by anything that never read a config file,
 * should see.
 */
final readonly class TierMap
{
    public function __construct(private ?Configuration $configuration = null) {}

    public function for(Finding $finding): Tier
    {
        return $this->configuration instanceof Configuration
            ? $this->configuration->tierFor($finding->ruleId, $finding->category)
            : Tier::defaultFor($finding->category);
    }
}
