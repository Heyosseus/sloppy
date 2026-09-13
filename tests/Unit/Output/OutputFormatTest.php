<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Output\OutputFormat;

it('parses the formats it supports, case and space insensitively', function (): void {
    expect(OutputFormat::parse('console'))->toBe(OutputFormat::Console)
        ->and(OutputFormat::parse(' JSON '))->toBe(OutputFormat::Json);
});

it('rejects an unknown format by name, listing every format now that there are five', function (): void {
    // This message grew when Sarif, Markdown and Github were added --
    // pinning the full list is what would have caught that growth breaking
    // a consumer that parses this message.
    expect(fn (): OutputFormat => OutputFormat::parse('xml'))
        ->toThrow(InvalidArgumentException::class, 'Unknown --format [xml]. Expected console or json or sarif or markdown or github.');
});

it('knows which formats must not be treated as console markup', function (): void {
    expect(OutputFormat::Json->isMachineReadable())->toBeTrue()
        ->and(OutputFormat::Console->isMachineReadable())->toBeFalse();
});

it('parses the three new formats', function (): void {
    expect(OutputFormat::parse('sarif'))->toBe(OutputFormat::Sarif)
        ->and(OutputFormat::parse('Markdown'))->toBe(OutputFormat::Markdown)
        ->and(OutputFormat::parse(' github '))->toBe(OutputFormat::Github);
});

it('expects redirection only for the formats meant to be parsed, not seen', function (): void {
    expect(OutputFormat::Json->expectsRedirection())->toBeTrue()
        ->and(OutputFormat::Sarif->expectsRedirection())->toBeTrue()
        // github's workflow commands must be seen by the Actions runner on
        // stdout, so telling a user to redirect it would break it.
        ->and(OutputFormat::Github->expectsRedirection())->toBeFalse()
        ->and(OutputFormat::Console->expectsRedirection())->toBeFalse()
        ->and(OutputFormat::Markdown->expectsRedirection())->toBeFalse();
});
