<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\DiffOptions;

/**
 * What `sloppy:diff` and `sloppy:review` share: a `base` revision argument
 * and the rest of {@see DiffOptions}, read the same way. Only which
 * explanation flag was read and whether the reading-order presentation was
 * asked for differ between the two commands.
 *
 * Kept separate from {@see SloppyCommandBase}, which `sloppy:scan` and
 * `sloppy:baseline` also extend and which declares no `base` argument --
 * `argument('base')` living there instead would be a call larastan can no
 * longer verify against every command's `$signature`.
 */
abstract class SloppyDiffLikeCommand extends SloppyCommandBase
{
    protected function diffOptionsFrom(
        bool $explain = false,
        bool $explainRisk = false,
        bool $review = false,
    ): DiffOptions {
        $base = $this->argument('base');
        $failOn = $this->stringOption('fail-on');

        return new DiffOptions(
            base: is_string($base) && trim($base) !== '' ? trim($base) : 'HEAD',
            paths: $this->stringListOption('path'),
            format: OutputFormat::parse($this->stringOption('format', 'console')),
            failOn: $failOn === '' ? null : $failOn,
            minConfidence: $this->intOption('min-confidence'),
            rules: $this->stringListOption('rule'),
            explain: $explain,
            explainRisk: $explainRisk,
            review: $review,
            coverage: $this->stringOption('coverage') === '' ? null : $this->stringOption('coverage'),
        );
    }
}
