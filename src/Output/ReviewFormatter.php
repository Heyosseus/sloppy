<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Contracts\DiffFormatter;
use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Scoring\Risk;
use Heyosseus\Sloppy\Scoring\RiskCalculator;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * The reading order for a change.
 *
 * Every other tool in this space hands a reviewer an inventory and leaves the
 * triage to them. This answers the question they actually have -- what should I
 * look at first? -- by ranking findings on risk rather than listing them by
 * file, and then splitting the changed files into three tiers so the reviewer
 * knows where to stop.
 *
 * Risk is what makes the ranking possible, and this is the one place where all
 * of its factors can be measured. Novelty comes from the diff's own
 * new-versus-inherited partition. Proximity comes from `ChangedFile::touches()`
 * -- a god method the change created and a god method it merely stood next to
 * are not the same finding, and no other tool can tell them apart because no
 * other tool has the hunks in hand.
 */
final readonly class ReviewFormatter implements DiffFormatter
{
    /**
     * Risk below which a file is worth a skim rather than a read.
     *
     * Five is roughly one medium-severity finding at full confidence with no
     * reach: real, worth knowing about, not worth reading first.
     */
    private const float SKIM_THRESHOLD = 5.0;

    /**
     * How many findings a file shows before the rest are counted rather than
     * listed.
     *
     * A command whose purpose is to reduce what a reviewer has to read cannot
     * answer with fourteen findings from one file. Measured on a real change:
     * the top-ranked file had fourteen, of which eight were the same rule at
     * the same confidence, and the wall of text buried the two that mattered.
     */
    private const int FINDINGS_PER_FILE = 4;

    public function __construct(
        private RiskCalculator $risk = new RiskCalculator,
        private bool $explainRisk = false,
        private bool $markdown = false,
    ) {}

    public function format(DiffReport $report): string
    {
        $lines = $this->markdown
            ? ['## Sloppy review', '']
            : ['', '  '.$this->bold('Sloppy review'), ''];
        $lines[] = $this->indent(0).$this->headline($report);
        $lines[] = '';

        $ranked = $this->rank($report);
        $byFile = $this->groupByFile($ranked);

        $read = array_filter($byFile, static fn (array $f): bool => $f['risk'] >= self::SKIM_THRESHOLD);
        $skim = array_filter($byFile, static fn (array $f): bool => $f['risk'] > 0.0 && $f['risk'] < self::SKIM_THRESHOLD);
        $quiet = $this->quietFiles($report, $byFile);

        if ($read !== []) {
            $lines[] = $this->heading('Read in this order');
            $lines[] = $this->markdown ? '' : '  ';

            $position = 0;

            foreach ($read as $path => $file) {
                $lines = [...$lines, ...$this->file(++$position, (string) $path, $file)];
            }

            $lines[] = '';
        }

        if ($skim !== []) {
            $lines[] = $this->heading('Skim');
            $lines[] = sprintf(
                '%s%d file(s), risk under %.0f',
                $this->indent(1),
                count($skim),
                self::SKIM_THRESHOLD,
            );

            foreach ($skim as $path => $file) {
                $lines[] = sprintf(
                    '%s%s',
                    $this->indent(2),
                    $this->dim(sprintf('%s  risk %.1f', $this->code($this->text((string) $path)), $file['risk'])),
                );
            }

            $lines[] = '';
        }

        if ($quiet !== []) {
            $lines[] = $this->heading('No attention needed');
            $lines[] = $this->indent(1).$this->dim(sprintf('%d file(s) changed with no findings', count($quiet)));
            $lines[] = '';
        }

        if ($report->resolved !== []) {
            $lines[] = $this->heading('Resolved by this change');

            foreach ($this->summariseResolved($report->resolved) as $line) {
                $lines[] = $this->indent(1).$this->coloured($this->text($line), 'green');
            }

            $lines[] = '';
        }

        if ($read === [] && $skim === []) {
            $lines[] = $this->indent(0).$this->coloured('Nothing in this change needs reading.', 'green');
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * Emphasis, in whichever language this report is being written in.
     *
     * The tier logic below is the valuable part and exists once. Only the
     * decoration differs between a terminal and a pull-request comment, so
     * only the decoration branches -- a second formatter duplicating the
     * structure is exactly what SL111 would report about this file.
     */
    private function bold(string $value): string
    {
        return $this->markdown ? '**'.$value.'**' : '<options=bold>'.$value.'</>';
    }

    private function dim(string $value): string
    {
        return $this->markdown ? $value : '<fg=gray>'.$value.'</>';
    }

    private function coloured(string $value, string $colour): string
    {
        return $this->markdown ? $value : '<fg='.$colour.'>'.$value.'</>';
    }

    private function code(string $value): string
    {
        return $this->markdown ? '`'.$value.'`' : $value;
    }

    /**
     * Console markup has to be escaped or a finding quoting `<p>` is read as a
     * tag; markdown has no such hazard here, and escaping it would put
     * backslashes into a comment.
     */
    private function text(string $value): string
    {
        return $this->markdown ? $value : OutputFormatter::escape($value);
    }

    /**
     * Section headings: a markdown heading, or an indented bold line.
     */
    private function heading(string $value): string
    {
        return $this->markdown ? '### '.$value : '  '.$this->bold($value);
    }

    private function indent(int $depth): string
    {
        return $this->markdown ? str_repeat('  ', max(0, $depth - 1)) : str_repeat('  ', $depth + 1);
    }

    private function headline(DiffReport $report): string
    {
        $delta = $report->scoreDelta();

        return sprintf(
            '%d file(s) changed, %s lines  ·  score %d → %d (%s%d)',
            $report->changedFileCount(),
            number_format($report->changedLineCount()),
            $report->baseScore->value,
            $report->currentScore->value,
            $delta >= 0 ? '+' : '',
            $delta,
        );
    }

    /**
     * Every finding the change is responsible for, with its risk measured.
     *
     * This is the only context in which all of the model's factors are
     * knowable: the diff says which findings are new, and the hunks say which
     * sit inside a line the change actually touched.
     *
     * @return list<array{finding: Finding, risk: Risk, isNew: bool}>
     */
    private function rank(DiffReport $report): array
    {
        $hunksByPath = [];

        foreach ($report->changedFiles as $file) {
            if ($file instanceof ChangedFile) {
                $hunksByPath[$file->relativePath] = $file;
            }
        }

        $ranked = [];

        foreach ([[$report->new, true], [$report->existing, false]] as [$findings, $isNew]) {
            /** @var list<Finding> $findings */
            foreach ($findings as $finding) {
                $file = $hunksByPath[$finding->location->relativePath] ?? null;

                $ranked[] = [
                    'finding' => $finding,
                    'risk' => $this->risk->for(
                        $finding,
                        isNew: $isNew,
                        inHunk: $file instanceof ChangedFile ? $file->touches($finding->location->line) : null,
                    ),
                    'isNew' => $isNew,
                ];
            }
        }

        usort($ranked, static function (array $a, array $b): int {
            /** @var array{finding: Finding, risk: Risk, isNew: bool} $a */
            /** @var array{finding: Finding, risk: Risk, isNew: bool} $b */
            return [$b['risk']->value, $a['finding']->location->relativePath, $a['finding']->location->line]
                <=> [$a['risk']->value, $b['finding']->location->relativePath, $b['finding']->location->line];
        });

        return $ranked;
    }

    /**
     * File risk is the RAW SUM of its findings, not their density.
     *
     * A file with twelve findings should be read before a file with one, even
     * if it is longer. Density is the right measure for quality and the slop
     * score already reports it; total is the right measure for attention.
     *
     * @param  list<array{finding: Finding, risk: Risk, isNew: bool}>  $ranked
     * @return array<string, array{risk: float, rows: list<array{finding: Finding, risk: Risk, isNew: bool}>}>
     */
    private function groupByFile(array $ranked): array
    {
        $files = [];

        foreach ($ranked as $row) {
            $path = $row['finding']->location->relativePath;

            $files[$path] ??= ['risk' => 0.0, 'rows' => []];

            $files[$path]['risk'] += $row['risk']->value;
            $files[$path]['rows'][] = $row;
        }

        uasort($files, static fn (array $a, array $b): int => $b['risk'] <=> $a['risk']);

        return $files;
    }

    /**
     * @param  array{risk: float, rows: list<array{finding: Finding, risk: Risk, isNew: bool}>}  $file
     * @return list<string>
     */
    private function file(int $position, string $path, array $file): array
    {
        $lines = [sprintf(
            '%s%d. %s   %s',
            $this->indent(1),
            $position,
            $this->bold($this->code($this->text($path))),
            $this->coloured(sprintf('risk %.1f', $file['risk']), 'yellow'),
        )];

        $shown = array_slice($file['rows'], 0, self::FINDINGS_PER_FILE);
        $remaining = array_slice($file['rows'], self::FINDINGS_PER_FILE);

        foreach ($shown as $row) {
            $finding = $row['finding'];

            $lines[] = sprintf(
                '%s%s %s %s  %s',
                $this->indent(3),
                $row['isNew'] ? $this->coloured('new', 'red') : $this->dim('old'),
                $this->bold($finding->ruleId),
                $this->text($finding->ruleName),
                $this->dim(sprintf(
                    '(%d%%, risk %.1f%s)',
                    $finding->confidence,
                    $row['risk']->value,
                    $row['risk']->proximityLabel === 'in hunk' ? ', in hunk' : '',
                )),
            );

            if ($this->explainRisk) {
                $lines[] = $this->indent(4).$this->dim($this->code($this->text($row['risk']->explain())));
            }
        }

        if ($remaining !== []) {
            $lines[] = $this->indent(3).$this->dim(sprintf(
                'and %d more in this file, %s',
                count($remaining),
                $this->summariseRemaining($remaining),
            ));
        }

        return $lines;
    }

    /**
     * The tail of a file's findings as counts by rule.
     *
     * "and 10 more in this file, 8 x SL204, 2 x SL104" tells a reviewer what
     * they are choosing not to read, which is the information a bare count
     * withholds.
     *
     * @param  list<array{finding: Finding, risk: Risk, isNew: bool}>  $rows
     */
    private function summariseRemaining(array $rows): string
    {
        $counts = [];

        foreach ($rows as $row) {
            $id = $row['finding']->ruleId;
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        ksort($counts);

        $parts = [];

        foreach ($counts as $id => $count) {
            $parts[] = sprintf('%d x %s', $count, $id);
        }

        return implode(', ', $parts);
    }

    /**
     * Changed files that produced no findings at all.
     *
     * Saying so is the point: a reviewer who knows nine of fourteen files need
     * no attention can spend all of it on the other five.
     *
     * @param  array<string, array{risk: float, rows: list<array{finding: Finding, risk: Risk, isNew: bool}>}>  $byFile
     * @return list<string>
     */
    private function quietFiles(DiffReport $report, array $byFile): array
    {
        $quiet = [];

        foreach ($report->changedFiles as $file) {
            if (! $file instanceof ChangedFile || ! $file->isAnalysable()) {
                continue;
            }

            if (! isset($byFile[$file->relativePath])) {
                $quiet[] = $file->relativePath;
            }
        }

        sort($quiet);

        return $quiet;
    }

    /**
     * @param  list<Finding>  $resolved
     * @return list<string>
     */
    private function summariseResolved(array $resolved): array
    {
        $byRule = [];

        foreach ($resolved as $finding) {
            $key = $finding->ruleId.' '.$finding->ruleName;
            $byRule[$key] = ($byRule[$key] ?? 0) + 1;
        }

        ksort($byRule);

        $lines = [sprintf('%d finding(s) no longer reported', count($resolved))];

        foreach ($byRule as $rule => $count) {
            $lines[] = sprintf('  %d x %s', $count, $rule);
        }

        return $lines;
    }
}
