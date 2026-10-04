<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

use InvalidArgumentException;

/**
 * A `sloppy.architecture` setting Sloppy cannot use. The message names the key
 * and what would work, because a profile that silently matches nothing is
 * worse than one that refuses to load.
 */
final class ProfileException extends InvalidArgumentException {}
