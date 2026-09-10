<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Git;

use RuntimeException;

/**
 * A git command that did not succeed.
 */
final class GitException extends RuntimeException {}
