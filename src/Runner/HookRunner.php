<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Agent\HookEvent;
use Heyosseus\Sloppy\Agent\HookPayload;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Output\HookFeedbackFormatter;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * Check an agent's work from inside the agent's own loop.
 *
 * Both events compare the working tree with `HEAD`, so only what the change
 * introduced is ever put in front of the agent: a legacy file's old debt is
 * not this task's business, and an agent told about it would go and "fix" it.
 *
 * Every way this can fail to run fails open. A hook that exits 2 on a missing
 * git binary would trap the agent in a loop it cannot get out of, which is a
 * far worse outcome than one unchecked edit.
 */
final readonly class HookRunner
{
    private const string BASE = 'HEAD';

    public function __construct(private HookFeedbackFormatter $formatter = new HookFeedbackFormatter) {}

    public function run(Sloppy $sloppy, HookEvent $event, HookPayload $payload): HookOutcome
    {
        if (! $sloppy->configuration->enabled()) {
            return HookOutcome::pass();
        }

        return match ($event) {
            HookEvent::PostEdit => $this->afterEdit($sloppy, $payload),
            HookEvent::Stop => $this->beforeStop($sloppy, $payload),
        };
    }

    private function afterEdit(Sloppy $sloppy, HookPayload $payload): HookOutcome
    {
        $relative = $this->relativePath($sloppy->configuration->basePath, $payload->filePath);

        if ($relative === null || ! str_ends_with(mb_strtolower($relative), '.php')) {
            return HookOutcome::pass();
        }

        $report = $this->diff($sloppy);

        if ($report instanceof HookOutcome) {
            return $report;
        }

        $introduced = array_values(array_filter(
            $report->new,
            static fn (Finding $finding): bool => $finding->location->relativePath === $relative,
        ));

        return $introduced === []
            ? HookOutcome::pass()
            : HookOutcome::block($this->formatter->forEdit($relative, $introduced));
    }

    private function beforeStop(Sloppy $sloppy, HookPayload $payload): HookOutcome
    {
        $report = $this->diff($sloppy);

        if ($report instanceof HookOutcome) {
            return $report;
        }

        // The retry after a block. Stopping again means the agent read the
        // findings and decided; blocking a second time would only prove that
        // a false positive can hold a session hostage.
        if ($payload->stopHookActive) {
            return HookOutcome::pass($report->new === [] ? '' : $this->formatter->leftovers($report->new));
        }

        $threshold = $sloppy->configuration->failOn();

        if (! $threshold instanceof Severity) {
            return HookOutcome::pass();
        }

        $blocking = $report->newAtOrAbove($threshold);

        return $blocking === []
            ? HookOutcome::pass()
            : HookOutcome::block($this->formatter->forStop($blocking, $threshold));
    }

    /**
     * The change against `HEAD`, or the reason there is none.
     */
    private function diff(Sloppy $sloppy): DiffReport|HookOutcome
    {
        $git = $sloppy->git();

        if (! $git->isAvailable()) {
            return HookOutcome::pass("Sloppy: git is not available on PATH, so this change was not checked.\n");
        }

        if (! $git->isRepository()) {
            return HookOutcome::pass(sprintf("Sloppy: %s is not a git repository, so this change was not checked.\n", $sloppy->configuration->basePath));
        }

        if (! $git->revisionExists(self::BASE)) {
            return HookOutcome::pass("Sloppy: there is no HEAD commit to compare with yet, so this change was not checked.\n");
        }

        try {
            return $sloppy->diff(self::BASE);
        } catch (Throwable $exception) {
            return HookOutcome::pass(sprintf("Sloppy: the check did not run (%s).\n", $exception->getMessage()));
        }
    }

    /**
     * The edited file relative to the project, or null when it is outside it.
     *
     * Agents send absolute paths, and on Windows with either slash, so both
     * sides are normalised before comparing. Drive letters differ in case
     * between tools, which is why the prefix test ignores case. The project
     * root was found through `realpath()`, so the file is compared both as
     * sent and resolved: a symlinked checkout or a Windows short name
     * (`RUNNER~1`) is the same file under a different spelling.
     */
    private function relativePath(string $basePath, ?string $filePath): ?string
    {
        if ($filePath === null) {
            return null;
        }

        $prefix = $basePath.'/';
        $resolved = realpath($filePath);

        foreach ([$filePath, $resolved === false ? $filePath : $resolved] as $candidate) {
            $normalised = str_replace('\\', '/', $candidate);

            if (stripos($normalised, $prefix) === 0) {
                return substr($normalised, strlen($prefix));
            }
        }

        $path = str_replace('\\', '/', $filePath);

        $isAbsolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;

        if ($isAbsolute || str_starts_with($path, '../')) {
            return null;
        }

        return str_starts_with($path, './') ? substr($path, 2) : $path;
    }
}
