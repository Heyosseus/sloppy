<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Rules\Architecture\AbstractionInflationRule;
use Heyosseus\Sloppy\Rules\Architecture\EmptyWrapperClassRule;
use Heyosseus\Sloppy\Rules\Architecture\SingleUseAbstractionRule;
use Heyosseus\Sloppy\Rules\Laravel\ExcessiveControllerDependenciesRule;
use Heyosseus\Sloppy\Rules\Laravel\ExcessiveServiceDependenciesRule;
use Heyosseus\Sloppy\Rules\Laravel\InlineValidationRule;
use Heyosseus\Sloppy\Rules\Laravel\LaravelCalls;
use Heyosseus\Sloppy\Rules\Laravel\ModelDoingTooMuchRule;
use Heyosseus\Sloppy\Rules\Laravel\PossibleNPlusOneRule;
use Heyosseus\Sloppy\Rules\Laravel\SuspiciousModelAllRule;
use Heyosseus\Sloppy\Rules\Php\DuplicateLogicRule;
use Heyosseus\Sloppy\Rules\Php\NarrativeCommentRule;
use Heyosseus\Sloppy\Rules\Php\RedundantConditionRule;
use Heyosseus\Sloppy\Rules\Php\UnusedConstructorDependencyRule;
use PhpParser\Node\Expr\Variable;

it('says nothing about an anonymous controller, service or model', function (): void {
    $constructor = <<<'PHP'
    <?php

    namespace App;

    $handler = new class extends \App\Http\Controllers\Controller
    {
        public function __construct(
            private readonly \App\A $a,
            private readonly \App\B $b,
            private readonly \App\C $c,
            private readonly \App\D $d,
            private readonly \App\E $e,
            private readonly \App\F $f,
            private readonly \App\G $g,
        ) {}
    };
    PHP;

    $model = <<<'PHP'
    <?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;

    $anonymous = new class extends Model
    {
        public function sendInvoice(): void
        {
            \Illuminate\Support\Facades\Mail::to('a@b.c')->send(new \App\Mail\Invoice);
        }
    };
    PHP;

    expect(findings(new ExcessiveControllerDependenciesRule, $constructor, 'app/Http/Controllers/Handler.php'))->toBe([])
        ->and(findings(new ExcessiveServiceDependenciesRule, $constructor, 'app/Services/Handler.php'))->toBe([])
        ->and(findings(new ModelDoingTooMuchRule, $model, 'app/Models/Anonymous.php'))->toBe([]);
});

it('says nothing about anonymous classes when looking at layers', function (): void {
    $files = [
        'app/Services/Anonymous.php' => "<?php\n\nnamespace App\\Services;\n\n\$s = new class { public function run(): void {} };\n",
        'app/Repositories/Anonymous.php' => "<?php\n\nnamespace App\\Repositories;\n\n\$r = new class { public function run(): void {} };\n",
    ];

    expect(findingsAcross(new SingleUseAbstractionRule, $files))->toBe([])
        ->and(findingsAcross(new AbstractionInflationRule, $files))->toBe([]);
});

it('ignores a class whose whole name is a layer suffix', function (): void {
    // `Service` with the suffix removed is nothing at all, so it belongs to no
    // stack: there is no concept it could be a layer around.
    $files = [
        'app/Services/Service.php' => "<?php\n\nnamespace App\\Services;\n\nclass Service\n{\n    public function run(): void {}\n}\n",
        'app/Repositories/Repository.php' => "<?php\n\nnamespace App\\Repositories;\n\nclass Repository\n{\n    public function run(): void {}\n}\n",
    ];

    expect(findingsAcross(new AbstractionInflationRule, $files))->toBe([]);
});

it('ignores a method that forwards to something other than an injected property', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    class Forwarder
    {
        public function __construct(private readonly Mailer $mailer) {}

        public function send(string $to): string
        {
            return $this->mailer->send($to);
        }

        public function other(string $to, Order $order): string
        {
            return $order->mailer->send($to);
        }
    }
    PHP;

    expect(findings(new EmptyWrapperClassRule, $source, 'app/Services/Forwarder.php'))->toBe([]);
});

it('reads a constructor that assigns to things other than its own properties', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    class Reporting
    {
        private $mailer;

        public function __construct(Mailer $mailer, Clock $clock, Logger $logger)
        {
            $local = $mailer;
            $other = new \stdClass;
            $other->clock = $clock;
            $this->mailer = $local;
        }

        public function run(): void
        {
            $this->mailer->send('x');
        }
    }
    PHP;

    // `$clock` and `$logger` are assigned nowhere this rule recognises, so
    // there is no property to call unused -- and it says nothing rather than
    // guessing.
    expect(findings(new UnusedConstructorDependencyRule, $source, 'app/Services/Reporting.php'))->toBe([]);
});

it('names every duplicate block, and counts the rest', function (): void {
    $method = <<<'PHP'
        public function %s(array $rows): array
        {
            $result = [];

            foreach ($rows as $row) {
                if ($row['active'] ?? false) {
                    $result[] = strtoupper((string) $row['name']);
                }
            }

            sort($result);
            $result = array_values($result);
            $result = array_unique($result);

            return $result;
        }
    PHP;

    $body = '';

    foreach (['first', 'second', 'third', 'fourth', 'fifth'] as $name) {
        $body .= sprintf($method, $name)."\n\n";
    }

    $source = "<?php\n\nnamespace App;\n\nclass Reporting\n{\n".$body."}\n";

    expect(messages(findings(new DuplicateLogicRule, $source, 'app/Reporting.php')))->toContain('and 1 more');
});

it('iterates a model query with no variable in between', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    use App\Models\Order;

    class Reporting
    {
        public function run(): void
        {
            foreach (Order::all() as $order) {
                echo $order->id;
            }
        }
    }
    PHP;

    expect(ruleIds(findings(new SuspiciousModelAllRule, $source, 'app/Services/Reporting.php')))->toBe(['SL210']);
});

it('reads a query chain as evidence that a loop is over records', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    use App\Models\Order;

    class Reporting
    {
        public function run(): void
        {
            foreach (Order::query()->where('paid', true)->get() as $order) {
                echo $order->customer->name;
            }
        }
    }
    PHP;

    expect(ruleIds(findings(new PossibleNPlusOneRule, $source, 'app/Services/Reporting.php')))->toContain('SL203');
});

it('reads a with() argument that is neither a string nor an array', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    use App\Models\Order;

    class Reporting
    {
        public function run(array $relations): void
        {
            foreach (Order::with($relations)->get() as $order) {
                echo $order->customer->name;
            }
        }
    }
    PHP;

    expect(findings(new PossibleNPlusOneRule, $source, 'app/Services/Reporting.php'))->toBeArray();
});

it('ignores a validate call with no arguments at all', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Http\Controllers;

    class OrderController
    {
        public function store($request): string
        {
            $request->validate();

            return 'ok';
        }
    }
    PHP;

    expect(findings(new InlineValidationRule, $source, 'app/Http/Controllers/OrderController.php'))->toBe([]);
});

it('ignores an always-true condition with nothing after it to simplify', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App;

    class Order
    {
        public function run(): string
        {
            if (true) {
                return 'always';
            }

            return 'never';
        }
    }
    PHP;

    expect(findings(new RedundantConditionRule, $source, 'app/Order.php'))->toBeArray();
});

it('reads a block comment, and a comment echoing the word beneath it', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App;

    class Order
    {
        public function run(): float
        {
            /* Work out the prices for the order line. */
            $orderPrices = [1.0];

            // Order price.
            $orderPrices = array_sum($orderPrices);

            return $orderPrices;
        }
    }
    PHP;

    expect(findings(new NarrativeCommentRule, $source, 'app/Order.php'))->toBeArray();
});

it('counts the statements in a file, ignoring its imports and declares', function (): void {
    $file = (new Parser)->parse('app/Example.php', <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace App;

    use App\Other;

    class Example
    {
        public function run(): void
        {
            $a = 1;
            $b = 2;
        }
    }
    PHP);

    // The declare, the namespace and the import are structure rather than
    // work, so none of them counts as a statement.
    expect(NodeHelper::countStatements($file->ast[0]))->toBe(0)
        ->and(NodeHelper::countStatements($file->ast[1]))->toBe(2);
});

it('names nothing for a node that is not a call, and no type for one that is not a type', function (): void {
    expect(NodeHelper::callName(new Variable('order')))->toBeNull()
        ->and(NodeHelper::typeToString(new Variable('order')))->toBeNull();
});

it('keeps its Laravel vocabulary static', function (): void {
    $reflection = new ReflectionClass(LaravelCalls::class);
    $constructor = $reflection->getConstructor();

    expect($constructor?->isPrivate())->toBeTrue();

    $constructor?->invoke($reflection->newInstanceWithoutConstructor());
});

it('does not count the concept itself as a layer around anything', function (): void {
    // Order, its repository, that repository's interface and a service on top:
    // three layers around one concept, and the concept is not one of them.
    $files = [
        'app/Models/Order.php' => "<?php\n\nnamespace App\Models;\n\nclass Order\n{\n    public function total(): int\n    {\n        return 1;\n    }\n}\n",
        'app/Repositories/OrderRepositoryInterface.php' => "<?php\n\nnamespace App\Repositories;\n\ninterface OrderRepositoryInterface\n{\n    public function find(int \$id): mixed;\n}\n",
        'app/Repositories/OrderRepository.php' => "<?php\n\nnamespace App\Repositories;\n\nclass OrderRepository implements OrderRepositoryInterface\n{\n    public function find(int \$id): mixed\n    {\n        return null;\n    }\n}\n",
        'app/Services/OrderService.php' => "<?php\n\nnamespace App\Services;\n\nclass OrderService\n{\n    public function __construct(private readonly \App\Repositories\OrderRepositoryInterface \$orders) {}\n\n    public function find(int \$id): mixed\n    {\n        return \$this->orders->find(\$id);\n    }\n}\n",
    ];

    expect(findingsAcross(new AbstractionInflationRule, $files))->toBeArray();
});

it('reads a query-builder method in a chain as evidence of database rows', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    class Reporting
    {
        public function __construct(private readonly Orders $orders) {}

        public function run(array $ids): void
        {
            foreach ($this->orders->whereIn('id', $ids)->get() as $order) {
                echo $order->customer->name;
            }
        }
    }
    PHP;

    expect(ruleIds(findings(new PossibleNPlusOneRule, $source, 'app/Services/Reporting.php')))->toContain('SL203');
});

it('reads an if with nothing inside it', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App;

    class Order
    {
        public function run(?Order $order): string
        {
            if ($order !== null) {
            }

            if ($order !== null) {
                return 'yes';
            }

            return 'no';
        }
    }
    PHP;

    expect(findings(new RedundantConditionRule, $source, 'app/Order.php'))->toBeArray();
});
