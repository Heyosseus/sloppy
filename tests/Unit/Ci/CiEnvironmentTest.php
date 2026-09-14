<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ci\CiEnvironment;
use Heyosseus\Sloppy\Ci\CiProvider;
use Heyosseus\Sloppy\Output\OutputFormat;

it('recognises GitHub Actions, GitLab, and neither', function (): void {
    expect((new CiEnvironment(['GITHUB_ACTIONS' => 'true']))->provider())->toBe(CiProvider::GitHubActions)
        ->and((new CiEnvironment(['GITLAB_CI' => 'true']))->provider())->toBe(CiProvider::GitLab)
        ->and((new CiEnvironment)->provider())->toBe(CiProvider::Unknown);
});

it('still knows it is in CI under a provider it does not recognise', function (): void {
    expect((new CiEnvironment(['CI' => 'true']))->isCi())->toBeTrue()
        ->and((new CiEnvironment(['CI' => 'true']))->provider())->toBe(CiProvider::Unknown)
        ->and((new CiEnvironment)->isCi())->toBeFalse()
        ->and((new CiEnvironment(['GITHUB_ACTIONS' => 'true']))->isCi())->toBeTrue();
});

it('knows a pull request from a push', function (): void {
    expect((new CiEnvironment(['GITHUB_BASE_REF' => 'main']))->isProposedChange())->toBeTrue()
        ->and((new CiEnvironment(['CI_MERGE_REQUEST_TARGET_BRANCH_NAME' => 'main']))->isProposedChange())->toBeTrue()
        ->and((new CiEnvironment(['GITHUB_REF' => 'refs/heads/main']))->isProposedChange())->toBeFalse();
});

it('tries the remote branch before the local one', function (): void {
    $environment = new CiEnvironment(['GITHUB_BASE_REF' => 'develop']);

    expect($environment->baseCandidates())->toBe(['origin/develop', 'develop']);
});

it('falls back to the default branch when nothing is being merged', function (): void {
    $environment = new CiEnvironment(['CI_DEFAULT_BRANCH' => 'trunk']);

    expect($environment->baseCandidates())->toBe(['origin/trunk', 'trunk']);
});

it('offers every branch it was told about, without repeating one', function (): void {
    $environment = new CiEnvironment([
        'CI_MERGE_REQUEST_TARGET_BRANCH_NAME' => 'main',
        'CI_DEFAULT_BRANCH' => 'main',
    ]);

    expect($environment->baseCandidates())->toBe(['origin/main', 'main']);
});

it('has no candidates outside CI', function (): void {
    expect((new CiEnvironment)->baseCandidates())->toBe([]);
});

it('reads the summary and output files, and treats blank as absent', function (): void {
    $environment = new CiEnvironment([
        'GITHUB_STEP_SUMMARY' => '/tmp/summary.md',
        'GITHUB_OUTPUT' => '  ',
    ]);

    expect($environment->summaryPath())->toBe('/tmp/summary.md')
        ->and($environment->outputPath())->toBeNull()
        ->and($environment->value('NOT_SET'))->toBeNull();
});

it('reads the real environment when asked', function (): void {
    putenv('SLOPPY_TEST_MARKER=present');

    expect(CiEnvironment::fromGlobals()->value('SLOPPY_TEST_MARKER'))->toBe('present');

    putenv('SLOPPY_TEST_MARKER');
});

it('knows which report each provider wants, and where it goes', function (): void {
    expect(CiProvider::GitHubActions->defaultFormat())->toBe(OutputFormat::Github)
        ->and(CiProvider::GitLab->defaultFormat())->toBe(OutputFormat::Gitlab)
        ->and(CiProvider::Unknown->defaultFormat())->toBe(OutputFormat::Console)
        ->and(CiProvider::GitLab->defaultReportPath())->toBe('gl-code-quality-report.json')
        ->and(CiProvider::GitHubActions->defaultReportPath())->toBeNull()
        ->and(CiProvider::Unknown->defaultReportPath())->toBeNull()
        ->and(CiProvider::GitHubActions->label())->toBe('GitHub Actions')
        ->and(CiProvider::GitLab->label())->toBe('GitLab CI')
        ->and(CiProvider::Unknown->label())->toBe('no recognised CI');
});
