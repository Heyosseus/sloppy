<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

use Heyosseus\Sloppy\Integrations\HealthSnapshot;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * The dashboard, as a list of lines.
 *
 * Nothing here touches a terminal, a clock or the filesystem: a frame is a
 * function of one {@see WatchState}. That is what lets the whole visual be
 * asserted on as strings, and it is why the loop can be tested without a TTY.
 *
 * Every line is sized before it is coloured, so console markup never counts
 * towards the width, and anything quoted from the analysed project is escaped
 * -- a file called `<p>.php` is source text, not markup.
 */
final readonly class FrameRenderer
{
    /** Cells in the score bar; one cell is five points. */
    private const int SCORE_BAR = 20;

    /** Cells in the longest category bar. */
    private const int CATEGORY_BAR = 12;

    public function __construct(
        private int $width = 80,
        private bool $keypresses = true,
    ) {}

    /**
     * @return list<string>
     */
    public function render(WatchState $state): array
    {
        return [
            '',
            ...$this->score($state->snapshot),
            ...$this->categories($state->snapshot),
            ...$this->findings($state),
            ...$this->footer($state),
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function score(HealthSnapshot $snapshot): array
    {
        $color = $snapshot->band->color();
        $filled = (int) round($snapshot->score / 100 * self::SCORE_BAR);

        return [
            sprintf(
                '  <fg=%s;options=bold>%d/100</>  <fg=%s>%s</>',
                $color,
                $snapshot->score,
                $color,
                OutputFormatter::escape($this->cut($snapshot->label(), $this->width - 12)),
            ),
            sprintf(
                '  <fg=%s>%s</><fg=gray>%s</>',
                $color,
                str_repeat('█', $filled),
                str_repeat('░', self::SCORE_BAR - $filled),
            ),
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function categories(HealthSnapshot $snapshot): array
    {
        if ($snapshot->byCategory === []) {
            return [];
        }

        $largest = max($snapshot->byCategory);
        $lines = [];

        foreach ($snapshot->byCategory as $category => $count) {
            $lines[] = sprintf(
                '  %-14s %3d  <fg=gray>%s</>',
                OutputFormatter::escape($this->cut($category, 14)),
                $count,
                str_repeat('█', max(1, (int) round($count / $largest * self::CATEGORY_BAR))),
            );
        }

        return [...$lines, ''];
    }

    /**
     * @return list<string>
     */
    private function findings(WatchState $state): array
    {
        if ($state->snapshot->top === []) {
            return ['  <fg=green>Nothing flagged.</>', ''];
        }

        $lines = ['  <options=bold>Read first</>'];

        // One budget for the whole list, from the longest location: the paths
        // line up in a column, which is what makes three of them scannable.
        $longest = 0;

        foreach ($state->snapshot->top as $row) {
            $longest = max($longest, mb_strlen($row['file'].':'.$row['line']));
        }

        foreach ($state->snapshot->top as $index => $row) {
            $lines[] = $this->finding($index + 1, $row, $index === $state->selected, max(0, $this->width - $longest - 6));
        }

        return [...$lines, ''];
    }

    /**
     * One ranked finding, numbered so it can be opened by number where
     * single keypresses are not available.
     *
     * The label gives up whatever room the locations need: a truncated path is
     * useless, while a truncated rule name is still recognisable.
     *
     * @param  array{rule: string, name: string, severity: string, file: string, line: int, message: string, risk: float}  $row
     */
    private function finding(int $number, array $row, bool $selected, int $budget): string
    {
        $location = $row['file'].':'.$row['line'];
        $label = sprintf('%d %s %s', $number, $row['rule'], $row['name']);

        $plain = sprintf('%s %s  %s', $selected ? '›' : ' ', str_pad($this->cut($label, $budget), $budget), $location);

        $escaped = OutputFormatter::escape($this->cut($plain, $this->width));

        return $selected ? '<fg=cyan>'.$escaped.'</>' : $escaped;
    }

    /**
     * @return list<string>
     */
    private function footer(WatchState $state): array
    {
        $parts = [sprintf('%d files', $state->snapshot->files)];

        if ($state->changed !== []) {
            $parts[] = $this->changed($state->changed);
        }

        if ($state->duration > 0.0) {
            $parts[] = sprintf('%.1fs', $state->duration);
        }

        return [$this->gray(implode(' · ', $parts)), $this->gray($this->hints())];
    }

    /**
     * One file is worth naming; a burst is worth counting.
     *
     * @param  list<string>  $changed
     */
    private function changed(array $changed): string
    {
        return count($changed) === 1
            ? 'changed '.basename($changed[0])
            : sprintf('%d files changed', count($changed));
    }

    private function hints(): string
    {
        // Where keypresses cannot be read there is nothing to offer but the
        // one key every terminal handles itself. Promising keys that will not
        // work is worse than admitting the dashboard is only watching.
        return $this->keypresses
            ? '↑↓ move  ↵ open  r rescan  q quit'
            : 'watching · Ctrl+C to quit';
    }

    private function gray(string $plain): string
    {
        return '  <fg=gray>'.OutputFormatter::escape($this->cut($plain, $this->width - 2)).'</>';
    }

    private function cut(string $text, int $width): string
    {
        return mb_strlen($text) > $width ? mb_substr($text, 0, max(0, $width)) : $text;
    }
}
