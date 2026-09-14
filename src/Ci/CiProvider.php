<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ci;

use Heyosseus\Sloppy\Output\OutputFormat;

/**
 * The CI system a run is happening inside.
 *
 * It decides one thing: which report shape lands where a reviewer will see it.
 * On GitHub that is workflow annotations on the diff; on GitLab it is a Code
 * Quality artifact the merge request widget reads; anywhere else it is the
 * console report a human is watching scroll past.
 */
enum CiProvider: string
{
    case GitHubActions = 'github-actions';
    case GitLab = 'gitlab-ci';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::GitHubActions => 'GitHub Actions',
            self::GitLab => 'GitLab CI',
            self::Unknown => 'no recognised CI',
        };
    }

    /**
     * The format to use when the command was not told which one to produce.
     */
    public function defaultFormat(): OutputFormat
    {
        return match ($this) {
            self::GitHubActions => OutputFormat::Github,
            self::GitLab => OutputFormat::Gitlab,
            self::Unknown => OutputFormat::Console,
        };
    }

    /**
     * Where a machine-readable report is conventionally written, so the
     * shipped templates and the command agree without the user restating it.
     */
    public function defaultReportPath(): ?string
    {
        return match ($this) {
            self::GitLab => 'gl-code-quality-report.json',
            self::GitHubActions, self::Unknown => null,
        };
    }
}
