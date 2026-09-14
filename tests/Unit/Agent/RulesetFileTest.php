<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Agent\RulesetFile;
use Heyosseus\Sloppy\Agent\RulesetFormat;

it('creates the block when there is no file yet', function (): void {
    $merged = RulesetFile::merge(null, "# Rules\n");

    expect($merged)->toStartWith(RulesetFile::BEGIN)
        ->and($merged)->toContain('# Rules')
        ->and($merged)->toEndWith(RulesetFile::END.PHP_EOL)
        ->and(RulesetFile::hasBlock($merged))->toBeTrue();
});

it('treats an empty file as no file', function (): void {
    expect(RulesetFile::merge("   \n", '# Rules'))->toStartWith(RulesetFile::BEGIN);
});

it('appends to a file that is already someone else\'s', function (): void {
    $existing = "# CLAUDE.md\n\nRun the tests with `composer test`.\n";

    $merged = RulesetFile::merge($existing, '# Rules');

    expect($merged)->toStartWith('# CLAUDE.md')
        ->and($merged)->toContain('Run the tests with `composer test`.')
        ->and($merged)->toContain(RulesetFile::BEGIN)
        ->and($merged)->toContain('# Rules');
});

it('replaces only its own block on a second run', function (): void {
    $first = RulesetFile::merge("# CLAUDE.md\n\nKeep this.\n", '# Rules v1');
    $second = RulesetFile::merge($first, '# Rules v2');

    expect($second)->toContain('Keep this.')
        ->and($second)->toContain('# Rules v2')
        ->and($second)->not->toContain('# Rules v1')
        ->and(mb_substr_count($second, RulesetFile::BEGIN))->toBe(1)
        ->and(mb_substr_count($second, RulesetFile::END))->toBe(1);
});

it('keeps what follows the block', function (): void {
    $existing = RulesetFile::merge('# Top', '# Rules')."\n## Afterwards\n\nStill here.\n";

    $merged = RulesetFile::merge($existing, '# Rules again');

    expect($merged)->toContain('# Top')
        ->and($merged)->toContain('## Afterwards')
        ->and($merged)->toContain('Still here.')
        ->and($merged)->toContain('# Rules again');
});

it('appends rather than guessing when the markers are damaged', function (): void {
    $broken = "# Notes\n\n".RulesetFile::END."\nsomething\n".RulesetFile::BEGIN."\n";

    $merged = RulesetFile::merge($broken, '# Rules');

    expect(RulesetFile::hasBlock($broken))->toBeTrue()
        ->and($merged)->toContain('# Notes')
        ->and($merged)->toContain('something')
        ->and($merged)->toEndWith(RulesetFile::END.PHP_EOL);
});

it('appends when only one marker is present', function (): void {
    $half = "# Notes\n\n".RulesetFile::BEGIN."\nhalf a block\n";

    expect(RulesetFile::hasBlock($half))->toBeFalse()
        ->and(RulesetFile::merge($half, '# Rules'))->toContain('half a block');
});

it('knows where each agent looks for its instructions', function (): void {
    expect(RulesetFormat::parse('claude')->defaultFile())->toBe('CLAUDE.md')
        ->and(RulesetFormat::parse('CURSOR')->defaultFile())->toBe('.cursorrules')
        ->and(RulesetFormat::parse(' agents ')->defaultFile())->toBe('AGENTS.md')
        ->and(RulesetFormat::Copilot->defaultFile())->toBe('.github/copilot-instructions.md')
        ->and(RulesetFormat::Windsurf->defaultFile())->toBe('.windsurfrules')
        ->and(RulesetFormat::Markdown->defaultFile())->toBe('sloppy-rules.md')
        ->and(RulesetFormat::Json->defaultFile())->toBe('sloppy-rules.json')
        ->and(RulesetFormat::Json->isJson())->toBeTrue()
        ->and(RulesetFormat::Claude->isJson())->toBeFalse();
});

it('rejects a format nobody reads', function (): void {
    expect(fn (): RulesetFormat => RulesetFormat::parse('emacs'))
        ->toThrow(InvalidArgumentException::class, 'Unknown ruleset format [emacs].');
});
