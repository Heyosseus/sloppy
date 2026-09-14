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

it('refuses to write a baseline it cannot encode', function (): void {
    // A fingerprint quoting bytes that are not valid UTF-8 cannot be JSON.
    $baseline = Baseline::fromFindings([finding(fingerprint: "Order::\xB1\x31")]);

    expect(fn (): mixed => (new BaselineManager)->save($baseline, sys_get_temp_dir().'/sloppy-never-written.json'))
        ->toThrow(RuntimeException::class, 'Baseline could not be encoded as JSON.');
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
