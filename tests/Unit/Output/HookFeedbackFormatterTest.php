<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Output\HookFeedbackFormatter;

it('writes one line per finding with the fix beside it, and no colour', function (): void {
    $text = (new HookFeedbackFormatter)->forEdit('app/A.php', [finding(rule: 'SL107', file: 'app/A.php', line: 12)]);

    expect($text)->toContain('this edit to app/A.php introduced 1 finding.')
        ->and($text)->toMatch('/- app\/A\.php:12 SL107 .+ \(high\): .+\n  Fix: /')
        ->and($text)->not->toContain("\e[");
});

it('caps a long list and says how much it left out', function (): void {
    $findings = array_map(static fn (int $line): Finding => finding(line: $line), range(1, 13));

    $text = (new HookFeedbackFormatter)->forStop($findings, Severity::Medium);

    expect($text)->toContain('introduced 13 findings at or above medium')
        ->and(substr_count($text, '  Fix: '))->toBe(HookFeedbackFormatter::LIMIT)
        ->and($text)->toContain('...and 3 more. Run `sloppy diff` for the full list.');
});

it('lists what is left when the agent finishes anyway', function (): void {
    $text = (new HookFeedbackFormatter)->leftovers([finding(), finding(line: 9)]);

    expect($text)->toStartWith('Sloppy: finishing with 2 findings still new in this change.');
});
