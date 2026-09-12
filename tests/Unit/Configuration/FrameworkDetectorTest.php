<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\FrameworkDetector;

it('detects laravel from the framework requirement', function (): void {
    $project = tempProject(['composer.json' => '{"require":{"laravel/framework":"^12.0"}}']);

    expect((new FrameworkDetector($project))->detect())->toBe(['laravel'])
        ->and((new FrameworkDetector($project))->has('laravel'))->toBeTrue();

    removeTree($project);
});

it('detects laravel from an illuminate requirement, as a package would have', function (): void {
    $project = tempProject(['composer.json' => '{"require-dev":{"illuminate/support":"^12.0"}}']);

    expect((new FrameworkDetector($project))->detect())->toBe(['laravel']);

    removeTree($project);
});

it('detects nothing in a vanilla project', function (): void {
    $project = tempProject(['composer.json' => '{"require":{"nikic/php-parser":"^5.3"}}']);

    expect((new FrameworkDetector($project))->detect())->toBe([])
        ->and((new FrameworkDetector($project))->has('laravel'))->toBeFalse();

    removeTree($project);
});

it('detects nothing rather than throwing when composer.json is missing or unreadable', function (): void {
    $missing = tempProject(['app/A.php' => '<?php']);
    $broken = tempProject(['composer.json' => '{not json']);

    expect((new FrameworkDetector($missing))->detect())->toBe([])
        ->and((new FrameworkDetector($broken))->detect())->toBe([]);

    removeTree($missing);
    removeTree($broken);
});
