<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

/**
 * Starting the editor.
 *
 * Behind an interface for the same reason {@see \Heyosseus\Sloppy\Integrations\Tooling\ToolRunner}
 * is: the behaviour worth testing is *which* editor is launched and when, and
 * a test that really launched one would open the maintainer's editor in the
 * middle of a suite.
 */
interface EditorLauncher
{
    /**
     * Run the editor and wait for it, if it is the waiting kind.
     *
     * @param  list<string>  $command  The editor and its arguments, paths already absolute.
     */
    public function launch(array $command): void;
}
