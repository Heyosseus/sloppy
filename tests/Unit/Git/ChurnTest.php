<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Configuration\RiskConfiguration;
use Heyosseus\Sloppy\Coverage\CoverageMap;
use Heyosseus\Sloppy\Git\ChurnMap;
use Heyosseus\Sloppy\Git\Git;
use Heyosseus\Sloppy\Scoring\RiskCalculator;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

it('counts the commits that touched each file, within the window, relative to the project', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('api/app/Busy.php', '<?php // 1')->write('api/app/Quiet.php', '<?php')->write('web/Other.php', '<?php')->commit('first');
    $repository->write('api/app/Busy.php', '<?php // 2')->commit('second');
    $repository->write('api/app/Busy.php', '<?php // 3')->commit('third');

    $project = new Git($repository->path.'/api');

    expect($project->churn(500))->toBe(['app/Busy.php' => 3, 'app/Quiet.php' => 1])
        ->and($project->churn(2))->toBe(['app/Busy.php' => 2]);

    $repository->remove();
});

it('reads no history outside a repository', function (): void {
    $outside = tempProject(['app/A.php' => '<?php']);

    expect((new Git($outside))->churn(500))->toBe([])
        ->and(ChurnMap::fromGit(new Git($outside), 500)->isEmpty())->toBeTrue();

    removeTree($outside);
});

it('answers null without history, and zero for a file the history never mentions', function (): void {
    $map = new ChurnMap(['app/Busy.php' => 7]);

    expect((new ChurnMap)->forFile('app/Busy.php'))->toBeNull()
        ->and($map->forFile('app/Busy.php'))->toBe(7)
        ->and($map->forFile('app/Untouched.php'))->toBe(0)
        ->and(ChurnMap::fromGit(new Git('/nowhere'), 0)->isEmpty())->toBeTrue();
});

it('lifts a finding in a file that keeps changing, by the documented amount', function (): void {
    $risk = new RiskCalculator(new RiskConfiguration, new CoverageMap, new ChurnMap(['app/Order.php' => 9, 'app/Other.php' => 1]));

    $measured = $risk->for(finding(confidence: 100, severity: Severity::High));

    // 1 + log10(1 + 9) x 0.5 = 1.5
    expect($measured->activity)->toBe(1.5)
        ->and($measured->changes)->toBe(9)
        ->and($measured->value)->toBe(15.0)
        ->and($measured->explain())->toEndWith('x 1.50 (9 recent changes) = 15.00')
        ->and($measured->toArray())->toMatchArray(['activity' => 1.5, 'recent_changes' => 9])
        ->and($risk->for(finding(file: 'app/Other.php'))->explain())->toContain('(1 recent change)');
});

it('leaves risk and its arithmetic untouched when there is no history', function (): void {
    $risk = (new RiskCalculator)->for(finding(confidence: 100, severity: Severity::High));

    expect($risk->activity)->toBe(1.0)
        ->and($risk->changes)->toBeNull()
        ->and($risk->explain())->not->toContain('recent change');
});

it('reads the churn weight and window from configuration', function (): void {
    $configured = RiskConfiguration::fromArray(['churn_weight' => 1, 'churn_commits' => 50]);
    $off = RiskConfiguration::fromArray(['churn_weight' => 0.0]);
    $malformed = RiskConfiguration::fromArray(['churn_weight' => 'lots', 'churn_commits' => 'many']);

    expect($configured->churnCommits)->toBe(50)
        ->and($configured->activityFor(9))->toBe(2.0)
        ->and($configured->readsHistory())->toBeTrue()
        ->and($off->readsHistory())->toBeFalse()
        ->and(RiskConfiguration::fromArray(['churn_commits' => -3])->readsHistory())->toBeFalse()
        ->and($malformed->churnCommits)->toBe(RiskConfiguration::CHURN_COMMITS)
        ->and($malformed->activityFor(9))->toBe(1.5)
        ->and($configured->activityFor(null))->toBe(1.0);
});

it('does not read history when the configuration gives it no weight', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('app/A.php', '<?php')->commit('first');

    $reading = new Sloppy(Configuration::fromArray([], $repository->path));
    $ignoring = new Sloppy(Configuration::fromArray(['risk' => ['churn_weight' => 0]], $repository->path));

    expect($reading->churn()->forFile('app/A.php'))->toBe(1)
        ->and($ignoring->churn()->isEmpty())->toBeTrue();

    $repository->remove();
});
