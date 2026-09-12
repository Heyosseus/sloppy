<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Support\StringListOption;

it('reduces an array option to its non-empty, trimmed strings', function (): void {
    expect(StringListOption::from([' SL101 ', '', 'SL102', '   ', 42, null]))
        ->toBe(['SL101', 'SL102']);
});

it('treats anything that is not an array as no option at all', function (): void {
    expect(StringListOption::from(null))->toBe([])
        ->and(StringListOption::from(false))->toBe([])
        ->and(StringListOption::from('SL101'))->toBe([]);
});
