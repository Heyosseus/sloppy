<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Architecture\AbstractionInflationRule;
use Heyosseus\Sloppy\Rules\Architecture\EmptyWrapperClassRule;
use Heyosseus\Sloppy\Rules\Architecture\SingleUseAbstractionRule;
use Heyosseus\Sloppy\Rules\Laravel\CollectionInsteadOfQueryRule;
use Heyosseus\Sloppy\Rules\Laravel\ExcessiveControllerDependenciesRule;
use Heyosseus\Sloppy\Rules\Laravel\ExcessiveServiceDependenciesRule;
use Heyosseus\Sloppy\Rules\Laravel\InlineValidationRule;
use Heyosseus\Sloppy\Rules\Laravel\ModelDoingTooMuchRule;
use Heyosseus\Sloppy\Rules\Laravel\PossibleNPlusOneRule;
use Heyosseus\Sloppy\Rules\Laravel\SuspiciousModelAllRule;
use Heyosseus\Sloppy\Rules\Php\DeadPrivateMethodRule;
use Heyosseus\Sloppy\Rules\Php\DuplicateLogicRule;
use Heyosseus\Sloppy\Rules\Php\GodClassRule;
use Heyosseus\Sloppy\Rules\Php\NarrativeCommentRule;
use Heyosseus\Sloppy\Rules\Php\RedundantConditionRule;
use Heyosseus\Sloppy\Rules\Php\SwallowedExceptionRule;
use Heyosseus\Sloppy\Rules\Php\UnusedConstructorDependencyRule;

/**
 * A file whose only class has no name. Every rule that reports on a class has
 * to skip it, because there is nothing it could call the finding.
 */
const ANONYMOUS_CLASS_FILE = <<<'PHP'
<?php

namespace App;

$handler = new class
{
    private string $unused = 'x';

    public function __construct(private readonly \App\Services\Mailer $mailer) {}

    public function handle(): string
    {
        $value = 'a';
        $other = 'b';

        return $value.$other;
    }

    private function neverCalled(): string
    {
        return 'unreachable';
    }
};
PHP;

it('says nothing about a class with no name', function (): void {
    $rules = [
        new GodClassRule,
        new DeadPrivateMethodRule,
        new DuplicateLogicRule,
        new EmptyWrapperClassRule,
        new SingleUseAbstractionRule,
        new AbstractionInflationRule,
        new ModelDoingTooMuchRule,
        new ExcessiveControllerDependenciesRule,
        new ExcessiveServiceDependenciesRule,
        new UnusedConstructorDependencyRule,
    ];

    foreach ($rules as $rule) {
        expect(findings($rule, ANONYMOUS_CLASS_FILE, 'app/Handler.php'))
            ->toBe([], sprintf('%s reported an anonymous class.', $rule->id()));
    }
});

it('ignores a controller and a service with no constructor', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Http\Controllers;

    class OrderController
    {
        public function index(): string
        {
            return 'ok';
        }
    }
    PHP;

    expect(findings(new ExcessiveControllerDependenciesRule, $source, 'app/Http/Controllers/OrderController.php'))->toBe([])
        ->and(findings(new ExcessiveServiceDependenciesRule, $source, 'app/Services/OrderService.php'))->toBe([]);
});

it('ignores a promoted dependency that is never assigned to a property', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    class Reporting
    {
        public function __construct(Mailer $mailer)
        {
            $local = $mailer;
            $this->{$local}();
        }
    }
    PHP;

    expect(findings(new UnusedConstructorDependencyRule, $source, 'app/Services/Reporting.php'))->toBe([]);
});

it('ignores a framework hook that nothing in the class calls', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Models;

    class Order
    {
        protected static function booted(): void
        {
            static::creating(fn () => null);
        }

        private function boot(): void
        {
        }
    }
    PHP;

    expect(ruleIds(findings(new DeadPrivateMethodRule, $source, 'app/Models/Order.php')))->toBe([]);
});

it('counts a comment only once when two statements share its line', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App;

    class Order
    {
        public function run(): void
        {
            // Set the total to the sum of the prices.
            $total = 0; $prices = [];
        }
    }
    PHP;

    expect(count(findings(new NarrativeCommentRule, $source, 'app/Order.php')))->toBeLessThanOrEqual(1);
});

it('ignores a docblock, which is documentation rather than narration', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App;

    class Order
    {
        /**
         * Set the total to the sum of the prices.
         */
        public function run(): void
        {
            $total = 0;
        }
    }
    PHP;

    expect(findings(new NarrativeCommentRule, $source, 'app/Order.php'))->toBe([]);
});

it('ignores an if whose condition is a constant other than true or false', function (): void {
    $source = <<<'PHP_WRAP'
    <?php
    
    namespace App;
    
    class Order
    {
        public function run(): string
        {
            if (PHP_EOL) {
                return 'yes';
            }
    
            return 'no';
        }
    }
    PHP_WRAP;

    expect(findings(new RedundantConditionRule, $source, 'app/Order.php'))->toBe([]);
});

it('treats echoing and recording a failure as handling it', function (): void {
    $echoes = <<<'PHP'
    <?php

    namespace App;

    class Order
    {
        public function run(): void
        {
            try {
                $this->pay();
            } catch (\Throwable $e) {
                echo 'payment failed';
            }
        }
    }
    PHP;

    $records = <<<'PHP'
    <?php

    namespace App;

    class Order
    {
        private $failure;

        public function run(): void
        {
            try {
                $this->pay();
            } catch (\Throwable $e) {
                $this->failure = $e;
            }
        }
    }
    PHP;

    expect(findings(new SwallowedExceptionRule, $echoes, 'app/Order.php'))->toBe([])
        ->and(findings(new SwallowedExceptionRule, $records, 'app/Order.php'))->toBe([]);
});

it('ignores a validator call that is neither make nor validate', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Http\Controllers;

    use Illuminate\Support\Facades\Validator;

    class OrderController
    {
        public function store(): string
        {
            Validator::extend('postcode', fn () => true);

            return 'ok';
        }
    }
    PHP;

    expect(findings(new InlineValidationRule, $source, 'app/Http/Controllers/OrderController.php'))->toBe([]);
});

it('counts nested rule arrays as one rule each', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Http\Controllers;

    class OrderController
    {
        public function store($request): string
        {
            $request->validate([
                'name' => ['required', 'string'],
                'email' => ['required', 'email'],
                'age' => ['required', 'integer'],
                'city' => ['required', 'string'],
                'street' => ['required', 'string'],
                'postcode' => ['required', 'string'],
                'country' => ['required', 'string'],
                'phone' => ['required', 'string'],
            ]);

            return 'ok';
        }
    }
    PHP;

    expect(ruleIds(findings(new InlineValidationRule, $source, 'app/Http/Controllers/OrderController.php')))
        ->toBe(['SL202']);
});

it('ignores a collection pipeline whose operation cannot be named', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    use App\Models\Order;

    class Reporting
    {
        public function run(string $operation): mixed
        {
            return Order::all()->$operation();
        }
    }
    PHP;

    expect(ruleIds(findings(new CollectionInsteadOfQueryRule, $source, 'app/Services/Reporting.php')))->toBe([]);
});

it('iterates a model query directly, without a variable in between', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    use App\Models\Order;

    class Reporting
    {
        public function run(): void
        {
            foreach (Order::all() as $order) {
                $order->total();
            }
        }
    }
    PHP;

    expect(ruleIds(findings(new SuspiciousModelAllRule, $source, 'app/Services/Reporting.php')))->toBe(['SL210']);
});

it('reads a loop whose calls cannot be named, and a typed parameter it cannot resolve', function (): void {
    $source = <<<'PHP'
    <?php

    namespace App\Services;

    class Reporting
    {
        public function run(iterable $orders, string $name): void
        {
            foreach ($orders as $order) {
                $order->$name();
            }
        }

        public function untyped($orders): void
        {
            foreach ($orders as $order) {
                $order->customer->name;
            }
        }
    }
    PHP;

    expect(findings(new PossibleNPlusOneRule, $source, 'app/Services/Reporting.php'))->toBeArray();
});
