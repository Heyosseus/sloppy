<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Tier;
use Heyosseus\Sloppy\Analysis\TierMap;
use Heyosseus\Sloppy\Contracts\Formatter;
use Heyosseus\Sloppy\Fix\NarrativeCommentRemover;
use Heyosseus\Sloppy\Help\Surface;
use Heyosseus\Sloppy\Integrations\Tooling\RectorRules;
use Heyosseus\Sloppy\Scoring\Risk;
use Heyosseus\Sloppy\Scoring\RiskCalculator;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * The console report for a run too long to read top to bottom.
 *
 * A medium-sized application gets hundreds of findings, and a list of
 * hundreds is a list nobody finishes: it took people days to work through,
 * most of it telling them a method was long or a comment narrated. So this
 * report answers a narrower question -- what should I do this week? -- in
 * four parts:
 *
 *   - Fix first: the defects, highest risk first, each file-and-rule pair
 *     once, cut off at `top`.
 *   - Hotspots: maintainability findings summarised per file, the files most
 *     worth a look first -- which, when git can say, means the ones that keep
 *     changing.
 *   - By rule: every rule's count, so nothing is hidden, only folded.
 *   - Next: the commands that shorten the list without reading it -- the
 *     automated fixes, and a baseline for the debt that is already there.
 *
 * A run short enough to read gets the full report instead, and `--all`
 * always does. Nothing here changes which findings exist, the score, or the
 * exit code; it changes only what is on the screen first.
 */
final readonly class TriageFormatter implements Formatter
{
    /**
     * How many files the hotspot section names.
     */
    private const int HOTSPOTS = 10;

    /**
     * How many of a group's other line numbers are spelled out.
     */
    private const int LINES_SHOWN = 5;

    /**
     * The widest a line of text runs, the same as the full report's.
     */
    private const int WIDTH = 84;

    public function __construct(
        private ConsoleFormatter $console = new ConsoleFormatter,
        private TierMap $tiers = new TierMap,
        private RiskCalculator $risk = new RiskCalculator,
        private Surface $surface = Surface::Standalone,
        private int $top = 20,
        private bool $hasBaseline = false,
    ) {}

    public function format(AnalysisResult $result): string
    {
        if ($result->count() <= $this->top) {
            return $this->console->format($result);
        }

        $byTier = $this->byTier($result->findings);
        $rules = $this->ruleRows($result);

        return $this->console->close([
            ...$this->console->header($result),
            ...$this->fixFirst($byTier[Tier::Defect->value]),
            ...$this->hotspots($byTier[Tier::Maintainability->value]),
            ...$this->byRule($rules),
            ...$this->next($result, (string) array_key_first($rules)),
        ], $result);
    }

    /**
     * @param  list<Finding>  $findings
     * @return array<string, list<Finding>>
     */
    private function byTier(array $findings): array
    {
        $byTier = array_fill_keys(array_column(Tier::cases(), 'value'), []);

        foreach ($findings as $finding) {
            $byTier[$this->tiers->for($finding)->value][] = $finding;
        }

        return $byTier;
    }

    /**
     * @param  list<Finding>  $defects
     * @return list<string>
     */
    private function fixFirst(array $defects): array
    {
        $lines = ['', '  <options=bold>Fix first</>'];

        if ($defects === []) {
            $lines[] = '  <fg=green>No defects: nothing here is an error waiting to happen.</>';

            return $lines;
        }

        $groups = $this->groups($defects);
        $shown = array_slice($groups, 0, $this->top);

        $lines[1] .= sprintf(
            '  <fg=gray>%s in %s, highest risk first%s</>',
            $this->plural(count($defects), 'defect'),
            $this->plural(count($groups), 'place'),
            count($shown) < count($groups) ? sprintf(' -- the first %d', count($shown)) : '',
        );

        $advised = [];

        // The advice for a rule is the same every time it fires, and eight
        // copies of one paragraph bury the eight places it applies to.
        foreach ($shown as ['lead' => $lead, 'others' => $others]) {
            $lines = [...$lines, ...$this->console->renderFinding($lead, $this->where($lead, $others), ! isset($advised[$lead->ruleId]))];
            $advised[$lead->ruleId] = true;
        }

        return $lines;
    }

    /**
     * Defects grouped by file and rule, in order of the riskiest in each.
     *
     * Three swallowed exceptions in one controller are one thing to go and
     * fix, not three entries between which a reader has to notice they are
     * the same.
     *
     * @param  list<Finding>  $defects
     * @return list<array{lead: Finding, others: list<int>}>
     */
    private function groups(array $defects): array
    {
        $groups = [];

        foreach ($this->risk->rank($defects) as ['finding' => $finding]) {
            $key = $finding->location->relativePath."\0".$finding->ruleId;

            if (isset($groups[$key])) {
                $groups[$key]['others'][] = $finding->location->line;

                continue;
            }

            $groups[$key] = ['lead' => $finding, 'others' => []];
        }

        return array_values($groups);
    }

    /**
     * @param  list<int>  $others
     */
    private function where(Finding $lead, array $others): string
    {
        $where = '<fg=white;options=bold>'.OutputFormatter::escape((string) $lead->location).'</>';

        if ($others === []) {
            return $where;
        }

        sort($others);
        $listed = implode(', ', array_slice($others, 0, self::LINES_SHOWN));

        return $where.sprintf(
            '  <fg=gray>and %d more in this file (line%s %s%s)</>',
            count($others),
            count($others) === 1 ? '' : 's',
            $listed,
            count($others) > self::LINES_SHOWN ? ', …' : '',
        );
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    private function hotspots(array $findings): array
    {
        if ($findings === []) {
            return [];
        }

        $byFile = [];

        foreach ($findings as $finding) {
            $byFile[$finding->location->relativePath][] = $finding;
        }

        $ranked = array_slice(array_keys($this->risk->byFile($findings)), 0, self::HOTSPOTS);

        $lines = [
            '',
            sprintf(
                '  <options=bold>Hotspots</>  <fg=gray>%s in %s, summarised -- the %s most worth a look</>',
                $this->plural(count($findings), 'maintainability finding'),
                $this->plural(count($byFile), 'file'),
                count($ranked) === 1 ? 'one' : count($ranked),
            ),
        ];

        foreach ($ranked as $path) {
            $lines[] = '';
            $lines[] = '  <fg=white;options=bold>'.OutputFormatter::escape($path).'</>'
                .$this->history($this->risk->for($byFile[$path][0]));
            foreach ($this->tally($byFile[$path]) as $tally) {
                $lines[] = '         '.OutputFormatter::escape($tally);
            }
        }

        if (count($byFile) > count($ranked)) {
            $lines[] = '';
            $more = count($byFile) - count($ranked);
            $lines[] = sprintf('  <fg=gray>and %s more file%s.</>', number_format($more), $more === 1 ? '' : 's');
        }

        return $lines;
    }

    private function history(Risk $risk): string
    {
        return $risk->changes === null
            ? ''
            : sprintf('  <fg=gray>%s in recent history</>', $this->plural($risk->changes, 'change'));
    }

    /**
     * "12 × Narrative Comment · 4 × God Method", most frequent first, wrapped
     * between entries rather than through one.
     *
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    private function tally(array $findings): array
    {
        $counts = [];

        foreach ($findings as $finding) {
            $counts[$finding->ruleName] = ($counts[$finding->ruleName] ?? 0) + 1;
        }

        uksort($counts, static fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        $lines = [''];

        foreach ($counts as $name => $count) {
            $entry = $count.' × '.$name;
            $last = count($lines) - 1;

            if ($lines[$last] === '') {
                $lines[$last] = $entry;
            } elseif (mb_strlen($lines[$last].' · '.$entry) > self::WIDTH) {
                $lines[] = $entry;
            } else {
                $lines[$last] .= ' · '.$entry;
            }
        }

        return $lines;
    }

    /**
     * Every rule that fired, most findings first.
     *
     * @return array<string, array{name: string, count: int, tier: Tier}>
     */
    private function ruleRows(AnalysisResult $result): array
    {
        $rows = [];

        foreach ($result->findings as $finding) {
            $rows[$finding->ruleId] ??= ['name' => $finding->ruleName, 'count' => 0, 'tier' => $this->tiers->for($finding)];
            $rows[$finding->ruleId]['count']++;
        }

        uksort($rows, static fn (string $a, string $b): int => [$rows[$b]['count'], $a] <=> [$rows[$a]['count'], $b]);

        return $rows;
    }

    /**
     * @param  array<string, array{name: string, count: int, tier: Tier}>  $rows
     * @return list<string>
     */
    private function byRule(array $rows): array
    {
        $lines = ['', '  <options=bold>By rule</>', ''];

        foreach ($rows as $id => ['name' => $name, 'count' => $count, 'tier' => $tier]) {
            $lines[] = sprintf(
                '    %-6s %-36s %6s  <fg=gray>%s</>',
                $id,
                OutputFormatter::escape($name),
                number_format($count),
                mb_strtolower($tier->label()),
            );
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function next(AnalysisResult $result, string $busiestRule): array
    {
        $lines = ['', '  <options=bold>Next</>', ''];

        $fixable = count(array_filter(
            $result->findings,
            static fn (Finding $finding): bool => RectorRules::fixes($finding->ruleId) || NarrativeCommentRemover::canRemove($finding),
        ));

        if ($fixable > 0) {
            $lines[] = sprintf(
                '    <fg=cyan>→</> %s an automated fix: <options=bold>%s</>',
                $fixable === 1 ? '1 finding has' : number_format($fixable).' findings have',
                $this->surface->command('fix'),
            );
        }

        if (! $this->hasBaseline) {
            $lines[] = sprintf(
                '    <fg=cyan>→</> No baseline yet. <options=bold>%s</> accepts %s,',
                $this->surface->command('baseline'),
                $result->count() === 1 ? 'this finding' : 'these '.number_format($result->count()),
            );
            $lines[] = '      and every scan after it reports only new ones.';
        }

        $lines[] = sprintf(
            '    <fg=cyan>→</> <options=bold>%s</> lists every finding; add <options=bold>--rule=%s</> for one rule.',
            $this->surface->command('scan', '--all'),
            $busiestRule,
        );

        return $lines;
    }

    private function plural(int $count, string $noun): string
    {
        return number_format($count).' '.$noun.($count === 1 ? '' : 's');
    }
}
