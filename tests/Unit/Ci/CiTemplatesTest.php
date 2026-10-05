<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Sloppy;

/**
 * The shipped CI templates install a version of Sloppy and run it with the
 * user's inputs. Both are easy to get quietly wrong: a default version left
 * behind by a release, and an input pasted into a shell script.
 */
function ciTemplate(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/'.$path);
}

function releaseConstraint(): string
{
    preg_match('/^(\d+)\.(\d+)\./', Sloppy::VERSION, $version);

    return '^'.$version[1].'.'.$version[2];
}

it('installs the current release by default, everywhere it installs one', function (): void {
    $constraint = releaseConstraint();

    expect(ciTemplate('action.yml'))->toMatch('/version:\s*\n(?:\s+\w[^\n]*\n)*?\s+default: \''.preg_quote($constraint, '/').'\'/')
        ->and(ciTemplate('resources/ci/gitlab-ci.yml'))->toContain('SLOPPY_VERSION: '.$constraint."\n")
        ->and(ciTemplate('.github/workflows/sloppy.yml'))->toContain("version: '".$constraint."'");
});

it('never interpolates an input or a ref into a shell script', function (): void {
    $action = ciTemplate('action.yml');

    preg_match_all('/^\s+run: \|?\n?((?:.*\n)*?)(?=^\s+- name:|\z)/m', $action, $scripts);
    preg_match_all('/^\s+run: (.+)$/m', $action, $inline);

    foreach ([...$scripts[1], ...$inline[1]] as $script) {
        expect($script)->not->toContain('${{');
    }

    expect($action)->toContain('SLOPPY_BASE_REF: ${{ github.base_ref }}')
        ->and($action)->toContain('SLOPPY_PATHS: ${{ inputs.paths }}')
        ->and($action)->toContain('set -f')
        ->and($action)->toContain('read -r -a paths <<< "$SLOPPY_PATHS"');
});
