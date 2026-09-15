<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Evidence;

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Location;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Contracts\EvidenceSource;

/**
 * SL502 -- this change silenced errors instead of fixing them.
 *
 * A baseline that grew inside a diff is not a type error; it is a record that
 * someone chose not to fix one. Nothing else in the PHP ecosystem reports it,
 * because reporting it requires knowing what changed.
 *
 * The finding lands on the source file the entries were added for, not on the
 * baseline, because that is the file a reviewer has open. A finding parked in a
 * config file is a finding nobody sees.
 */
final readonly class BaselineGrowthSource implements EvidenceSource
{
    /**
     * @param  list<string>  $baselines
     */
    public function __construct(
        private array $baselines = ['phpstan-baseline.neon', 'psalm-baseline.xml'],
    ) {}

    public function id(): string
    {
        return 'SL502';
    }

    public function name(): string
    {
        return 'Baseline Growth';
    }

    public function description(): string
    {
        return 'Flags static-analysis baseline entries added by this change, which silence errors rather than fix them.';
    }

    public function explanation(): string
    {
        return 'Adding to a baseline records that an error exists and will not be dealt with. That is sometimes the '
            .'right call, but it is a decision worth seeing in review rather than one that arrives as a green build: '
            .'the error is still there, and the baseline is where it stopped being counted.';
    }

    public function category(): Category
    {
        return Category::Suppression;
    }

    public function severity(): Severity
    {
        return Severity::Medium;
    }

    public function evidence(EvidenceContext $context): iterable
    {
        foreach ($this->baselines as $baseline) {
            yield from $this->compare($context, $baseline);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function compare(EvidenceContext $context, string $baseline): iterable
    {
        $path = rtrim(str_replace('\\', '/', $context->basePath), '/').'/'.$baseline;

        if (! is_file($path)) {
            return;
        }

        $current = BaselineEntries::for($baseline, (string) file_get_contents($path));

        if ($current === []) {
            return;
        }

        $previousContents = $context->git->showFile($context->baseRevision, $baseline);

        if ($previousContents === null) {
            yield $this->introduced($baseline, array_sum($current));

            return;
        }

        $previous = BaselineEntries::for($baseline, $previousContents);

        foreach ($current as $file => $count) {
            $added = $count - ($previous[$file] ?? 0);

            if ($added <= 0) {
                continue;
            }

            yield $this->grew($baseline, $file, $added);
        }
    }

    /**
     * The baseline did not exist at the base revision.
     *
     * Reported once rather than once per path: adopting a baseline is a single
     * decision, and splitting it across every file it mentions would bury the
     * one thing worth reviewing.
     */
    private function introduced(string $baseline, int $added): Finding
    {
        return $this->finding(
            path: $baseline,
            baseline: $baseline,
            added: $added,
            message: sprintf(
                'A %s was introduced here, silencing %d existing error%s.',
                $baseline,
                $added,
                $added === 1 ? '' : 's',
            ),
            suggestion: 'Review the baseline deliberately: everything in it is a problem that will now go unreported.',
        );
    }

    private function grew(string $baseline, string $file, int $added): Finding
    {
        return $this->finding(
            path: $file,
            baseline: $baseline,
            added: $added,
            message: sprintf(
                'This change silenced %d error%s for this file in %s.',
                $added,
                $added === 1 ? '' : 's',
                $baseline,
            ),
            suggestion: 'Fix the reported errors, or say in the pull request why they are being baselined instead.',
        );
    }

    private function finding(string $path, string $baseline, int $added, string $message, string $suggestion): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            ruleName: $this->name(),
            category: $this->category(),
            severity: $this->severity(),
            confidence: 85,
            location: new Location(relativePath: $path, line: 1),
            message: $message,
            explanation: $this->explanation(),
            suggestion: $suggestion,
            fingerprint: $baseline.':'.$path,
            metrics: ['added' => $added, 'baseline' => $baseline],
        );
    }
}
