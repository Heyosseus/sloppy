<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ci\CiProvider;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Runner\CiBaseResolver;
use Heyosseus\Sloppy\Runner\CiOptions;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

beforeEach(function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }
});

it('takes a commit spelled out by hash at its word', function (): void {
    $repository = TempRepository::create()->write('src/A.php', "<?php\n")->commit('first');
    $first = trim($repository->git(['rev-parse', 'HEAD']));
    $repository->write('src/B.php', "<?php\n")->commit('second');

    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['src']], $repository->path));
    $output = new RecordingRunnerOutput;

    expect((new CiBaseResolver)->resolve($sloppy, new CiOptions(base: $first), CiProvider::Unknown, $output))->toBe($first)
        ->and($output->messages())->toBe([]);

    $repository->remove();
});

it('compares with the tip of a branch it shares no history with, and says why', function (): void {
    $repository = TempRepository::create()->write('src/A.php', "<?php\n")->commit('first');
    $lonely = trim($repository->git(['commit-tree', trim($repository->git(['write-tree'])), '-m', 'lonely']));
    $repository->git(['branch', 'lonely', $lonely]);

    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['src']], $repository->path));
    $output = new RecordingRunnerOutput;

    expect((new CiBaseResolver)->resolve($sloppy, new CiOptions(base: 'lonely'), CiProvider::Unknown, $output))->toBe('lonely')
        ->and($output->messages())->toBe([
            'warn: No merge base between lonely and HEAD -- a shallow clone needs fetch-depth: 0 -- so comparing with its tip.',
        ]);

    $repository->remove();
});
