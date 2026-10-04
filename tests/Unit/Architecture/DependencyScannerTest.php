<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\DependencyScanner;

it('finds every class a declaration names, once, in source order', function (): void {
    $class = firstClass('namespace App;
        use Illuminate\Support\Collection;
        #[\App\Attributes\Audited]
        final class Report extends Base implements Contracts\Renders {
            use Concerns\Formats;
            public function __construct(private Clock $clock) {}
            public function build(?Filter $filter): Collection|Page {
                try {
                    $x = new Row(Status::Open, Status::class, Report::class);
                    if ($x instanceof Row) { return Factory::make(); }
                } catch (BuildFailed $e) {}
                return strlen(PHP_EOL) > 0 ? self::empty() : static::empty();
            }
        }');

    expect(array_keys(DependencyScanner::references($class)))->toBe([
        'App\Attributes\Audited',
        'App\Base',
        'App\Contracts\Renders',
        'App\Concerns\Formats',
        'App\Clock',
        'App\Filter',
        'Illuminate\Support\Collection',
        'App\Page',
        'App\Row',
        'App\Status',
        'App\Factory',
        'App\BuildFailed',
    ]);
});

it('points at the first mention of each class', function (): void {
    $class = firstClass("namespace App;\nclass A {\n    public function a() {\n        return new B();\n    }\n    public function b() { return new B(); }\n}");

    expect(DependencyScanner::references($class)['App\B']->getStartLine())->toBe(6);
});
