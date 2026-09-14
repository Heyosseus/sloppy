<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ci\BaseRevision;
use Heyosseus\Sloppy\Ci\CiEnvironment;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

it('asks for what the user asked for first, then the remote copy of it', function (): void {
    expect(BaseRevision::candidates('main', new CiEnvironment))->toBe(['main', 'origin/main'])
        ->and(BaseRevision::candidates('  ', new CiEnvironment(['GITHUB_BASE_REF' => 'dev'])))->toBe(['origin/dev', 'dev'])
        ->and(BaseRevision::candidates('origin/main', new CiEnvironment))->toBe(['origin/main', 'origin/origin/main']);
});

it('resolves the first revision that exists in the repository', function (): void {
    $repository = TempRepository::create()
        ->write('app/Order.php', "<?php\n\nclass Order {}\n")
        ->commit('first');

    expect(BaseRevision::resolve($repository->client(), 'HEAD', new CiEnvironment))->toBe('HEAD')
        ->and(BaseRevision::resolve($repository->client(), 'main', new CiEnvironment))->toBe('main')
        ->and(BaseRevision::resolve($repository->client(), 'nope', new CiEnvironment))->toBeNull();

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('falls back to the branch the CI environment named', function (): void {
    $repository = TempRepository::create()
        ->write('app/Order.php', "<?php\n\nclass Order {}\n")
        ->commit('first');

    $environment = new CiEnvironment(['GITHUB_BASE_REF' => 'main']);

    // `origin/main` does not exist in a repository with no remote, so the
    // local branch is what resolves -- which is the case on a developer's
    // machine, and the reason both are tried.
    expect(BaseRevision::resolve($repository->client(), null, $environment))->toBe('main');

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');
