<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

use Heyosseus\Sloppy\Contracts\Rule;
use Heyosseus\Sloppy\Rules\BaseRule;

/**
 * What a file is for, which decides the rules that read it.
 *
 * A migration is long and repetitive by design, and a routes file is a list of
 * closures: the size and duplication rules would report every one of them and
 * teach people to stop reading. Those files get the rules written for them,
 * plus the few defect rules that mean the same thing anywhere.
 */
enum SourceKind: string
{
    case Code = 'code';
    case Routes = 'routes';
    case Migration = 'migration';

    /**
     * Whether a rule reads files of this kind. A rule that does not extend
     * {@see BaseRule} cannot say, so it reads code only -- which is all any
     * rule read before kinds existed.
     */
    public function admits(Rule $rule): bool
    {
        return $rule instanceof BaseRule
            ? in_array($this, $rule->sourceKinds(), true)
            : $this === self::Code;
    }
}
