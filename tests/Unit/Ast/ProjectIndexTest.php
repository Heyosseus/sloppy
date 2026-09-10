<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;

/**
 * @param  array<string, string>  $files
 */
function indexOf(array $files): ProjectIndex
{
    $parser = new Parser;
    $parsed = [];

    foreach ($files as $path => $source) {
        $trimmed = ltrim($source);
        $parsed[] = $parser->parse($path, str_starts_with($trimmed, '<?php') ? $trimmed : "<?php\n\n".$trimmed);
    }

    return ProjectIndex::build($parsed);
}

it('keys declarations by fully qualified name', function (): void {
    $index = indexOf([
        'app/Order.php' => "namespace App\\Models;\n\nclass Order extends Model {}",
    ]);

    expect(array_keys($index->classes()))->toBe(['App\Models\Order'])
        ->and($index->class('App\Models\Order')?->shortName)->toBe('Order')
        ->and($index->class('App\Models\Order')?->relativePath)->toBe('app/Order.php')
        ->and($index->class('Order'))->toBeNull();
});

it('records what implements and extends what', function (): void {
    $index = indexOf([
        'app/Clock.php' => "namespace App;\n\ninterface Clock {}",
        'app/SystemClock.php' => "namespace App;\n\nclass SystemClock implements Clock {}",
        'app/FrozenClock.php' => "namespace App;\n\nclass FrozenClock implements Clock {}",
        'app/Base.php' => "namespace App;\n\nabstract class Base {}",
        'app/Child.php' => "namespace App;\n\nclass Child extends Base {}",
    ]);

    expect($index->implementationsOf('App\Clock'))->toBe(['App\SystemClock', 'App\FrozenClock'])
        ->and($index->implementationsOf('App\Base'))->toBe(['App\Child'])
        ->and($index->implementationsOf('App\Nothing'))->toBe([]);
});

it('counts consuming files but not implementations or imports', function (): void {
    $index = indexOf([
        'app/Clock.php' => "namespace App;\n\ninterface Clock {}",
        // Implementing is not consuming.
        'app/SystemClock.php' => "namespace App;\n\nclass SystemClock implements Clock {}",
        // Importing alone is not consuming either.
        'app/Unused.php' => "namespace Other;\n\nuse App\\Clock;\n\nclass Unused {}",
        // This one actually uses it.
        'app/Consumer.php' => "namespace App;\n\nclass Consumer\n{\n    public function __construct(private Clock \$clock) {}\n}",
    ]);

    expect($index->usagesOf('App\Clock'))->toBe(['app/Consumer.php'])
        ->and($index->usageCount('App\Clock'))->toBe(1);
});

it('counts a consuming file once however often it mentions the name', function (): void {
    $index = indexOf([
        'app/Clock.php' => "namespace App;\n\ninterface Clock {}",
        'app/Consumer.php' => <<<'PHP'
        namespace App;

        class Consumer
        {
            public function __construct(private Clock $a, private Clock $b) {}

            public function make(): Clock
            {
                return new SystemClock();
            }

            public function check(mixed $x): bool
            {
                return $x instanceof Clock;
            }
        }
        PHP,
    ]);

    expect($index->usageCount('App\Clock'))->toBe(1);
});

it('groups structurally identical method bodies', function (): void {
    $body = <<<'PHP'
        {
            $rows = [];
            $total = 0;

            foreach ($this->source->all() as $record) {
                $amount = $this->converter->toBase($record->total);
                $total = $total + $amount;
                $rows[] = ['id' => $record->id];
            }

            return ['rows' => $rows, 'total' => $total];
        }
    PHP;

    $index = indexOf([
        'app/A.php' => "class A\n{\n    public function build(): array\n".$body."\n}",
        'app/B.php' => "class B\n{\n    public function build(): array\n".$body."\n}",
    ]);

    $groups = array_values(array_filter(
        $index->duplicateBlocks(),
        static fn (array $blocks): bool => count($blocks) > 1,
    ));

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toHaveCount(2)
        ->and($groups[0][0]->label())->toBe('A::build()')
        ->and($groups[0][0]->reference())->toBe('app/A.php:5')
        ->and($index->blocksMatching('nope'))->toBe([]);
});

it('summarises the shape of each declaration', function (): void {
    $index = indexOf([
        'app/Thin.php' => <<<'PHP'
        namespace App;

        class Thin
        {
            public function __construct(private Dep $dep) {}

            public function go(): mixed
            {
                return $this->dep->go();
            }
        }
        PHP,
    ]);

    $summary = $index->class('App\Thin');

    expect($summary?->kind)->toBe('class')
        ->and($summary?->methodCount)->toBe(2)
        ->and($summary?->publicMethodNames)->toBe(['__construct', 'go'])
        ->and($summary?->dependencyCount)->toBe(1)
        ->and($summary?->isAbstract)->toBeFalse()
        ->and($summary?->isTrivial())->toBeTrue();
});

it('strips layer suffixes down to the base noun', function (): void {
    $index = indexOf([
        'app/A.php' => "namespace App;\n\ninterface OrderRepositoryInterface {}",
        'app/B.php' => "namespace App;\n\nclass OrderRepository {}",
        'app/C.php' => "namespace App;\n\nclass Order {}",
    ]);

    $suffixes = ['Interface', 'Repository'];

    expect($index->class('App\OrderRepositoryInterface')?->baseNoun($suffixes))->toBe('Order')
        ->and($index->class('App\OrderRepository')?->baseNoun($suffixes))->toBe('Order')
        ->and($index->class('App\Order')?->baseNoun($suffixes))->toBe('Order');
});

it('skips anonymous classes, which have no name to index', function (): void {
    $index = indexOf([
        'app/A.php' => "namespace App;\n\nclass A\n{\n    public function make(): object\n    {\n        return new class {};\n    }\n}",
    ]);

    expect(array_keys($index->classes()))->toBe(['App\A']);
});
