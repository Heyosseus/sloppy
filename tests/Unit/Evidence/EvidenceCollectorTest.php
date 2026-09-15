<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Evidence\EvidenceCollector;
use Heyosseus\Sloppy\Git\Git;

it('respects the configuration rule list', function (): void {
    // SL502 has to be switchable off the same way every other finding is, or a
    // team that disagrees with it has to stop using diff mode.
    expect(EvidenceCollector::fromConfiguration(Configuration::fromArray([], __DIR__))->ids())
        ->toBe(['SL502']);

    expect(EvidenceCollector::fromConfiguration(
        Configuration::fromArray(['rules' => ['SL502' => ['enabled' => false]]], __DIR__),
    )->ids())->toBe([]);
});

it('collects nothing when it has no sources', function (): void {
    expect(EvidenceCollector::of([])->collect(new Git(__DIR__), 'HEAD', []))->toBe([]);
});
