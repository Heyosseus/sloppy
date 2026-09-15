<?php

declare(strict_types=1);

use Filament\Panel;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Integrations\Filament\SloppyHealthWidget;
use Heyosseus\Sloppy\Integrations\Filament\SloppyPlugin;
use Heyosseus\Sloppy\Integrations\HealthReporter;
use Heyosseus\Sloppy\Sloppy;

/**
 * Point the container at a throwaway project, the way the widget will find it
 * in a real application.
 */
function widgetProject(string $source): string
{
    $root = tempProject([
        'composer.json' => '{"require":{"filament/filament":"^4.0"},"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/App.php' => $source,
    ]);

    app()->instance(Sloppy::class, new Sloppy(Configuration::fromArray(['paths' => ['src']], $root)));

    return $root;
}

it('registers its widget with a panel', function (): void {
    $panel = new Panel;

    $plugin = SloppyPlugin::make();
    $plugin->register($panel);
    $plugin->boot($panel);

    expect($plugin->getId())->toBe('sloppy')
        ->and($panel->registeredWidgets)->toBe([SloppyHealthWidget::class]);
});

it('lets a panel supply its own widgets instead', function (): void {
    $panel = new Panel;

    SloppyPlugin::make()->widgets([SloppyHealthWidget::class, SloppyHealthWidget::class])->register($panel);

    expect($panel->registeredWidgets)->toHaveCount(2);
});

it('renders the score, the severities and what to read first', function (): void {
    $root = widgetProject(godMethodSource());

    $html = (new SloppyHealthWidget)->render()->render();

    expect($html)->toContain('Sloppy score')
        ->and($html)->toContain('/100')
        ->and($html)->toContain('1 finding(s) across 1 file(s).')
        ->and($html)->toContain('high')
        ->and($html)->toContain('Read first')
        ->and($html)->toContain('SL101')
        ->and($html)->toContain('src/App.php:5');

    removeTree($root);
});

it('renders a clean project without a read-first list', function (): void {
    $root = widgetProject("<?php\n\nnamespace App;\n\nclass App\n{\n}\n");

    $html = (new SloppyHealthWidget)->render()->render();

    expect($html)->toContain('100')
        ->and($html)->toContain('0 finding(s) across 1 file(s).')
        ->and($html)->not->toContain('Read first');

    removeTree($root);
});

it('reads the snapshot the rest of the package shares', function (): void {
    $root = widgetProject(godMethodSource());

    $widget = new SloppyHealthWidget;
    $reporter = new HealthReporter(app(Sloppy::class));

    expect($widget->snapshot()->score)->toBe($reporter->current()->score)
        ->and(is_file($root.'/.sloppy-health.json'))->toBeTrue();

    removeTree($root);
});

it('is resolvable from the container for a NativePHP or Filament application', function (): void {
    expect(app(HealthReporter::class))->toBeInstanceOf(HealthReporter::class)
        ->and(app(Heyosseus\Sloppy\Integrations\NativePhp\DesktopHealth::class))
        ->toBeInstanceOf(Heyosseus\Sloppy\Integrations\NativePhp\DesktopHealth::class);
});

/**
 * The widget shipped once styled in Tailwind utility classes, which Filament
 * v4 does not compile for vendor views -- it rendered as an unstyled stack of
 * text inside a real panel while every content assertion above still passed.
 * Carrying its own CSS is the fix, so this asserts the fix rather than the
 * symptom.
 */
it('carries its own styles instead of depending on the panel compiling utilities', function (): void {
    $root = widgetProject(godMethodSource());

    $html = (new SloppyHealthWidget)->render()->render();

    expect($html)->toContain('<style>')
        ->and($html)->toContain('.fi-wi-sloppy {')
        ->and($html)->toMatch('/data-band="[a-z_]+"/');

    foreach (['rounded-xl', 'bg-white', 'text-3xl', 'text-gray-500', 'bg-success-50'] as $utility) {
        expect($html)->not->toContain($utility);
    }

    removeTree($root);
});
