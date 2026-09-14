<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Integrations\HealthSnapshot;
use Heyosseus\Sloppy\Integrations\NativePhp\DesktopHealth;
use Heyosseus\Sloppy\Sloppy;

/**
 * @return array{0: DesktopHealth, 1: string}
 */
function desktopFor(string $source): array
{
    $root = tempProject([
        'composer.json' => '{"require":{"nativephp/electron":"^1.0"},"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/App.php' => $source,
    ]);

    return [new DesktopHealth(new Sloppy(Configuration::fromArray(['paths' => ['src']], $root))), $root];
}

it('knows a NativePHP application from any other Laravel one', function (): void {
    $desktop = tempProject(['composer.json' => '{"require":{"nativephp/electron":"^1.0"}}']);
    $web = tempProject(['composer.json' => '{"require":{"laravel/framework":"^12.0"}}']);

    expect(DesktopHealth::isDesktopApp(Configuration::fromArray([], $desktop)))->toBeTrue()
        ->and(DesktopHealth::isDesktopApp(Configuration::fromArray([], $web)))->toBeFalse();

    removeTree($desktop);
    removeTree($web);
});

it('fits the state into a menu bar label', function (): void {
    [$desktop, $root] = desktopFor(godMethodSource());

    $label = $desktop->menuLabel();

    expect($label)->toStartWith('!')
        ->and($label)->toContain('/100');

    removeTree($root);
});

it('shows a tick when there is nothing to say and a tilde when there is a little', function (): void {
    [$desktop, $root] = desktopFor("<?php\n\nnamespace App;\n\nclass App\n{\n}\n");

    $clean = HealthSnapshot::fromArray(['score' => 100, 'findings' => 0]);
    $some = HealthSnapshot::fromArray(['score' => 90, 'findings' => 2, 'by_severity' => ['low' => 2]]);
    $bad = HealthSnapshot::fromArray(['score' => 40, 'findings' => 2, 'by_severity' => ['critical' => 2]]);

    expect($desktop->menuLabel($clean))->toBe('OK 100/100')
        ->and($desktop->menuLabel($some))->toBe('~ 90/100')
        ->and($desktop->menuLabel($bad))->toBe('! 40/100')
        ->and($clean->countAtOrAbove(Severity::High))->toBe(0);

    removeTree($root);
});

it('builds the menu behind the label out of the summary and the top findings', function (): void {
    [$desktop, $root] = desktopFor(godMethodSource());

    $items = $desktop->menuItems();

    expect($items[0]['label'])->toContain('finding(s) across')
        ->and($items[0]['detail'])->toContain('lines analysed')
        ->and($items[1]['label'])->toBe('SL101 God Method')
        ->and($items[1]['detail'])->toStartWith('src/App.php:');

    removeTree($root);
});

it('notifies about a score that dropped, and stays quiet about one that did not', function (): void {
    [$desktop, $root] = desktopFor(godMethodSource());

    $before = HealthSnapshot::fromArray(['score' => 95, 'findings' => 0]);
    $after = HealthSnapshot::fromArray([
        'score' => 70,
        'findings' => 3,
        'top' => [['rule' => 'SL101', 'file' => 'src/App.php']],
    ]);

    $notification = $desktop->notification($before, $after);

    expect($notification)->toBe([
        'title' => 'Sloppy: 70/100 Clean',
        'body' => '3 finding(s). Start with SL101 in src/App.php.',
    ])
        ->and($desktop->notification($after, $before))->toBeNull()
        ->and($desktop->notification(null, HealthSnapshot::fromArray(['score' => 80, 'findings' => 2, 'files' => 4])))
        ->toBe([
            'title' => 'Sloppy: 80/100 Clean',
            'body' => '2 finding(s) across 4 file(s).',
        ]);

    removeTree($root);
});

it('analyses when nothing was handed to it', function (): void {
    [$desktop, $root] = desktopFor(godMethodSource());

    expect($desktop->snapshot()->findings)->toBe(1)
        ->and($desktop->snapshot(fresh: true)->findings)->toBe(1)
        ->and($desktop->notification())->toBeArray();

    removeTree($root);
});
