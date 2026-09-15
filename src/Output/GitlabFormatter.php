<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Contracts\Formatter;

/**
 * GitLab Code Quality, which is the Code Climate issue format.
 *
 * Written as an artifact, GitLab reads it and shows each finding in the merge
 * request widget and on the changed lines themselves -- and works out which
 * findings are new by comparing the report against the target branch's, which
 * is why this is a whole-project report rather than a diff.
 *
 * @see https://docs.gitlab.com/ci/testing/code_quality/
 */
final readonly class GitlabFormatter implements Formatter
{
    public function __construct(private bool $pretty = true) {}

    public function format(AnalysisResult $result): string
    {
        $issues = [];

        foreach ($result->findings as $finding) {
            $issues[] = $this->issue($finding);
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ($this->pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $encoded = json_encode($issues, $flags);

        return ($encoded === false ? '[]' : $encoded)."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(Finding $finding): array
    {
        return [
            'type' => 'issue',
            'check_name' => $finding->ruleId,
            'description' => sprintf('%s: %s', $finding->ruleName, $finding->message),
            'content' => [
                'body' => sprintf("%s\n\n%s", $finding->explanation, $finding->suggestion),
            ],
            'categories' => [$this->category($finding->category)],
            'severity' => $this->severity($finding->severity),
            // GitLab uses the fingerprint to follow one finding across
            // pipelines, so it must be the identity that survives the file
            // moving down a few lines -- not a hash of the line it is on.
            'fingerprint' => $finding->identity(),
            'location' => [
                'path' => $finding->location->relativePath,
                'lines' => [
                    'begin' => max(1, $finding->location->line),
                    'end' => max(1, $finding->location->endLine ?? $finding->location->line),
                ],
            ],
        ];
    }

    /**
     * Code Climate defines a closed set of categories; ours map onto it.
     */
    private function category(Category $category): string
    {
        return match ($category) {
            Category::Complexity => 'Complexity',
            Category::Duplication => 'Duplication',
            Category::Performance => 'Performance',
            Category::Readability => 'Clarity',
            Category::DeadCode, Category::Architecture, Category::Dependencies, Category::Laravel => 'Style',
            // A silenced error is still an error, so suppression maps onto risk
            // rather than style: the point of the family is that the problem is
            // still there and has merely stopped being counted.
            Category::ErrorHandling, Category::Suppression => 'Bug Risk',
        };
    }

    /**
     * GitLab's five levels, which do not line up with ours by name: its
     * `critical` is one step below `blocker`, so our critical maps there.
     */
    private function severity(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical => 'blocker',
            Severity::High => 'critical',
            Severity::Medium => 'major',
            Severity::Low => 'minor',
            Severity::Info => 'info',
        };
    }
}
