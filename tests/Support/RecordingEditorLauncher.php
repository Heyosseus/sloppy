<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Tests\Support;

use Heyosseus\Sloppy\Watch\EditorLauncher;

/**
 * An editor that is never actually launched.
 *
 * Opening a finding is worth testing; opening the maintainer's editor in the
 * middle of a test run is not.
 */
final class RecordingEditorLauncher implements EditorLauncher
{
    /** @var list<list<string>> */
    public array $launched = [];

    public function launch(array $command): void
    {
        $this->launched[] = $command;
    }
}
