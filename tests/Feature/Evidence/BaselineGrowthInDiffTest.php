<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

/**
 * @param  list<Finding>  $findings
 * @return list<Finding>
 */
function onlyEvidence(array $findings): array
{
    return array_values(array_filter(
        $findings,
        static fn (Finding $finding): bool => $finding->ruleId === 'SL502',
    ));
}

it('reports baseline growth in a diff but never scores it', function (): void {
    // The score must stay a function of the tree alone. If SL502 entered it,
    // `sloppy scan` and `sloppy diff main` would print different scores for
    // the same working tree, which is indefensible.
    $repository = TempRepository::create()
        ->write('app/Importer.php', '<?php class Importer { public function run(): int { return 1; } }')
        ->write('phpstan-baseline.neon', "parameters:\n\tignoreErrors:\n")
        ->commit('base');

    $repository
        ->write('app/Importer.php', '<?php class Importer { public function run(): int { return 2; } }')
        ->write('phpstan-baseline.neon', neonFor('app/Importer.php', 5));

    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['app']], $repository->path));

    $report = $sloppy->diff('HEAD');
    $scan = $sloppy->analyze();
    $evidence = onlyEvidence($report->new);

    expect($evidence)->toHaveCount(1)
        ->and($evidence[0]->metrics['added'])->toBe(5)
        // The scan and the diff must agree about the tree.
        ->and($report->currentScore->value)->toBe($scan->score->value);

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('collects evidence even when no PHP file changed', function (): void {
    // A commit that only adds baseline entries is the purest form of this
    // defect, and it is exactly the case the "nothing analysable changed"
    // short circuit would otherwise swallow.
    $repository = TempRepository::create()
        ->write('app/Importer.php', '<?php class Importer {}')
        ->write('phpstan-baseline.neon', "parameters:\n\tignoreErrors:\n")
        ->commit('base');

    $repository->write('phpstan-baseline.neon', neonFor('app/Importer.php', 2));

    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['app']], $repository->path));

    expect(onlyEvidence($sloppy->diff('HEAD')->new))->toHaveCount(1);

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('reports no evidence when the configuration disables it', function (): void {
    $repository = TempRepository::create()
        ->write('app/Importer.php', '<?php class Importer {}')
        ->write('phpstan-baseline.neon', "parameters:\n\tignoreErrors:\n")
        ->commit('base');

    $repository->write('phpstan-baseline.neon', neonFor('app/Importer.php', 3));

    $sloppy = new Sloppy(Configuration::fromArray([
        'paths' => ['app'],
        'rules' => ['SL502' => ['enabled' => false]],
    ], $repository->path));

    expect(onlyEvidence($sloppy->diff('HEAD')->new))->toBeEmpty();

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('watches the baseline files named in the rule options', function (): void {
    // The filename is a rule option, the same place SL501 reads its
    // vocabulary, so a project using a tool we have not heard of configures it
    // rather than waiting for a release.
    $repository = TempRepository::create()
        ->write('app/Importer.php', '<?php class Importer {}')
        ->write('house-style-baseline.neon', "parameters:\n\tignoreErrors:\n")
        ->commit('base');

    $repository->write('house-style-baseline.neon', neonFor('app/Importer.php', 4));

    $sloppy = new Sloppy(Configuration::fromArray([
        'paths' => ['app'],
        'rules' => ['SL502' => ['files' => ['house-style-baseline.neon']]],
    ], $repository->path));

    $evidence = onlyEvidence($sloppy->diff('HEAD')->new);

    expect($evidence)->toHaveCount(1)
        ->and($evidence[0]->metrics['baseline'])->toBe('house-style-baseline.neon')
        ->and($evidence[0]->metrics['added'])->toBe(4);

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('watches nothing when the rule options name no files', function (): void {
    $repository = TempRepository::create()
        ->write('app/Importer.php', '<?php class Importer {}')
        ->write('phpstan-baseline.neon', "parameters:\n\tignoreErrors:\n")
        ->commit('base');

    $repository->write('phpstan-baseline.neon', neonFor('app/Importer.php', 4));

    $sloppy = new Sloppy(Configuration::fromArray([
        'paths' => ['app'],
        'rules' => ['SL502' => ['files' => []]],
    ], $repository->path));

    expect(onlyEvidence($sloppy->diff('HEAD')->new))->toBeEmpty();

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');
