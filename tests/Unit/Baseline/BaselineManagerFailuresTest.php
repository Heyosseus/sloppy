<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Baseline\Baseline;
use Heyosseus\Sloppy\Baseline\BaselineManager;
use Heyosseus\Sloppy\Tests\Support\UnreadableStream;

it('says it could not read a baseline that is not a readable file', function (): void {
    $root = tempProject(['composer.json' => '{}']);
    mkdir($root.'/baseline.json');

    // A directory where the baseline should be: `is_file()` says no, so the
    // manager answers "no baseline" rather than failing -- and a path it does
    // consider a file but cannot read is the case below.
    expect((new BaselineManager)->load($root.'/baseline.json'))->toBeNull();

    removeTree($root);
});

it('says it could not read a baseline that is there but will not open', function (): void {
    UnreadableStream::register();

    expect(fn (): mixed => (new BaselineManager)->load(UnreadableStream::path('baseline.json')))
        ->toThrow(RuntimeException::class, 'could not be read');

    UnreadableStream::unregister();
});

it('writes a baseline whose fingerprint is not valid UTF-8, and still matches the finding', function (): void {
    // A fingerprint quoting bytes that are not valid UTF-8 cannot be JSON as
    // it is. The bytes are replaced in the file; the entry is matched by its
    // identity, which was hashed from the original, so it still applies.
    $finding = finding(fingerprint: "Order::\xB1\x31");
    $path = sys_get_temp_dir().'/sloppy-utf8-'.bin2hex(random_bytes(4)).'.json';

    (new BaselineManager)->save(Baseline::fromFindings([$finding]), $path);

    expect((new BaselineManager)->load($path)?->allowanceFor($finding))->toBe(1);

    unlink($path);
});

it('says which directory it could not create', function (): void {
    $root = tempProject(['occupied' => 'a file where a directory should be']);

    expect(fn (): mixed => (new BaselineManager)->save(Baseline::fromFindings([]), $root.'/occupied/baseline.json'))
        ->toThrow(RuntimeException::class, 'could not be created');

    removeTree($root);
});

it('says which file it could not write', function (): void {
    $root = tempProject(['composer.json' => '{}']);
    mkdir($root.'/baseline.json');

    expect(fn (): mixed => (new BaselineManager)->save(Baseline::fromFindings([]), $root.'/baseline.json'))
        ->toThrow(RuntimeException::class, 'could not be written');

    removeTree($root);
});
