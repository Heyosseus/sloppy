<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Output\OutputFormat;

it('parses the formats it supports, case and space insensitively', function (): void {
    expect(OutputFormat::parse('console'))->toBe(OutputFormat::Console)
        ->and(OutputFormat::parse(' JSON '))->toBe(OutputFormat::Json);
});

it('rejects an unknown format by name', function (): void {
    expect(fn (): OutputFormat => OutputFormat::parse('xml'))
        ->toThrow(InvalidArgumentException::class, 'Unknown --format [xml]. Expected console or json.');
});

it('knows which formats must not be treated as console markup', function (): void {
    expect(OutputFormat::Json->isMachineReadable())->toBeTrue()
        ->and(OutputFormat::Console->isMachineReadable())->toBeFalse();
});
