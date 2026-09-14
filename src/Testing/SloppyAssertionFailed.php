<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Testing;

use PHPUnit\Framework\AssertionFailedError;

/**
 * A Sloppy assertion that did not hold.
 *
 * It extends PHPUnit's failure rather than a plain exception so Pest reports
 * it as a failing expectation -- red, with the message, in the list of
 * failures -- and not as an error, which is what a test suite shows when the
 * test itself broke.
 *
 * PHPUnit is not a dependency of this package. This class is only ever loaded
 * from inside a test run, where it is by definition present.
 */
final class SloppyAssertionFailed extends AssertionFailedError {}
