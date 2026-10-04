<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * A rule that enforces something the project declared about its architecture.
 *
 * With nothing declared it has nothing to say, so the registry leaves it out
 * of the run -- and out of the rulesets written for agents -- rather than
 * listing a rule that can never fire.
 */
interface PolicyRule
{
    public function appliesTo(Profile $profile): bool;
}
