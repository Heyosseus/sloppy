<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Output\OutputFormat;
use InvalidArgumentException;

/**
 * What `sloppy architecture` was asked: the whole project, or one class.
 */
final readonly class ArchitectureOptions
{
    /**
     * @param  string|null  $class  A fully qualified or short class name to explain; null for the overview.
     */
    public function __construct(
        public ?string $class = null,
        public OutputFormat $format = OutputFormat::Console,
    ) {
        if (! in_array($format, [OutputFormat::Console, OutputFormat::Json], true)) {
            throw new InvalidArgumentException(sprintf(
                'sloppy architecture writes console or json, not %s.',
                $format->value,
            ));
        }
    }
}
