<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Evidence\BaselineGrowthSource;
use Heyosseus\Sloppy\Evidence\EvidenceContext;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

function neonFor(string $path, int $count): string
{
    return "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: \"#^Something\.$#\"\n\t\t\tcount: {$count}\n\t\t\tpath: {$path}\n";
}

/**
 * @return list<Heyosseus\Sloppy\Analysis\Finding>
 */
function baselineEvidence(?string $before, string $after): array
{
    $repository = TempRepository::create()->write('app/Importer.php', '<?php class Importer {}');

    if ($before !== null) {
        $repository->write('phpstan-baseline.neon', $before);
    }

    $repository->commit('base')->write('phpstan-baseline.neon', $after);

    $findings = iterator_to_array((new BaselineGrowthSource)->evidence(new EvidenceContext(
        git: $repository->client(),
        basePath: $repository->path,
        baseRevision: 'HEAD',
        changedFiles: [],
    )), false);

    $repository->remove();

    return $findings;
}

it('reports entries added for a path', function (): void {
    $findings = baselineEvidence(neonFor('app/Importer.php', 1), neonFor('app/Importer.php', 4));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('SL502')
        ->and($findings[0]->location->relativePath)->toBe('app/Importer.php')
        ->and($findings[0]->metrics['added'])->toBe(3)
        ->and($findings[0]->metrics['baseline'])->toBe('phpstan-baseline.neon');
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('says nothing when a regenerated baseline keeps the same counts', function (): void {
    // `phpstan --generate-baseline` rewrites the whole file. A textual diff
    // would fire on every regeneration and be muted within a week.
    $findings = baselineEvidence(
        neonFor('app/Importer.php', 2),
        "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: \"#^Totally different wording\.$#\"\n\t\t\tcount: 2\n\t\t\tpath: app/Importer.php\n",
    );

    expect($findings)->toBeEmpty();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('says nothing when the baseline shrank', function (): void {
    $findings = baselineEvidence(neonFor('app/Importer.php', 9), neonFor('app/Importer.php', 2));

    expect($findings)->toBeEmpty();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('reports an introduced baseline once, not once per path', function (): void {
    // Introducing a baseline is a legitimate one-time act. Emitting N findings
    // for it would be the loudest possible way to punish a reasonable decision.
    $findings = baselineEvidence(null, <<<'NEON'
    parameters:
    	ignoreErrors:
    		-
    			message: "#^A\.$#"
    			count: 4
    			path: app/Importer.php
    		-
    			message: "#^B\.$#"
    			count: 3
    			path: app/Billing.php
    NEON);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('introduced')
        ->and($findings[0]->metrics['added'])->toBe(7);
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('says nothing when there is no baseline at all', function (): void {
    $findings = baselineEvidence(null, '');

    expect($findings)->toBeEmpty();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('describes itself the way every other detector does', function (): void {
    // SL502 is not a Rule, but the config list, `sloppy rules` and the
    // formatters all key off these six answers -- that is what Detector is for.
    $source = new BaselineGrowthSource;

    expect($source->id())->toBe('SL502')
        ->and($source->name())->toBe('Baseline Growth')
        ->and($source->description())->toContain('baseline')
        ->and($source->explanation())->toContain('baseline')
        ->and($source->category())->toBe(Category::Suppression)
        ->and($source->severity())->toBe(Severity::Medium);
});
