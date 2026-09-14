<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Output\ConsoleFormatter;
use Heyosseus\Sloppy\Output\FormatterFactory;
use Heyosseus\Sloppy\Output\GithubFormatter;
use Heyosseus\Sloppy\Output\GitlabFormatter;
use Heyosseus\Sloppy\Output\JsonFormatter;
use Heyosseus\Sloppy\Output\MarkdownFormatter;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Output\RectorFormatter;
use Heyosseus\Sloppy\Output\SarifFormatter;
use Heyosseus\Sloppy\Sloppy;

it('builds the formatter each format names', function (): void {
    $factory = new FormatterFactory(new Sloppy(Configuration::fromArray([], sys_get_temp_dir())));

    expect($factory->for(OutputFormat::Console))->toBeInstanceOf(ConsoleFormatter::class)
        ->and($factory->for(OutputFormat::Json))->toBeInstanceOf(JsonFormatter::class)
        ->and($factory->for(OutputFormat::Sarif))->toBeInstanceOf(SarifFormatter::class)
        ->and($factory->for(OutputFormat::Markdown))->toBeInstanceOf(MarkdownFormatter::class)
        ->and($factory->for(OutputFormat::Github))->toBeInstanceOf(GithubFormatter::class)
        ->and($factory->for(OutputFormat::Gitlab))->toBeInstanceOf(GitlabFormatter::class)
        ->and($factory->for(OutputFormat::Rector))->toBeInstanceOf(RectorFormatter::class);
});

it('passes the explanation flags through to the console report', function (): void {
    $factory = new FormatterFactory(
        sloppy: new Sloppy(Configuration::fromArray([], sys_get_temp_dir())),
        explain: true,
        explainRisk: true,
        failOn: Severity::High,
    );

    $report = $factory->for(OutputFormat::Console)->format(analysisResult([finding()]));

    expect($report)->toContain('An explanation.')
        ->and($report)->toContain('risk');
});

it('covers every format the enum declares', function (): void {
    $factory = new FormatterFactory(new Sloppy(Configuration::fromArray([], sys_get_temp_dir())));

    foreach (OutputFormat::cases() as $format) {
        expect($factory->for($format)->format(analysisResult([finding()])))->toBeString()->not->toBe('');
    }
});
