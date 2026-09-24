<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ast\NodeHelper;
use PhpParser\Node\Stmt\ClassLike;

describe('classification', function (): void {
    it('recognises a controller by namespace, name or parent', function (): void {
        expect(NodeHelper::isController(firstClass("namespace App\\Http\\Controllers;\n\nclass Thing {}")))->toBeTrue()
            ->and(NodeHelper::isController(firstClass('class OrderController {}')))->toBeTrue()
            ->and(NodeHelper::isController(firstClass('class Orders extends Controller {}')))->toBeTrue()
            ->and(NodeHelper::isController(firstClass('class OrderService {}')))->toBeFalse();
    });

    it('recognises an Eloquent model by its parent', function (): void {
        expect(NodeHelper::isEloquentModel(firstClass('class Order extends Model {}')))->toBeTrue()
            ->and(NodeHelper::isEloquentModel(firstClass("use Illuminate\\Database\\Eloquent\\Model;\n\nclass Order extends Model {}")))->toBeTrue()
            ->and(NodeHelper::isEloquentModel(firstClass('class User extends Authenticatable {}')))->toBeFalse()
            ->and(NodeHelper::isEloquentModel(firstClass('class Order {}')))->toBeFalse();
    });

    it('recognises form requests, services, jobs and middleware', function (): void {
        expect(NodeHelper::isFormRequest(firstClass('class StoreOrder extends FormRequest {}')))->toBeTrue()
            ->and(NodeHelper::isServiceClass(firstClass('class OrderService {}')))->toBeTrue()
            ->and(NodeHelper::isServiceClass(firstClass("namespace App\\Actions;\n\nclass PlaceOrder {}")))->toBeTrue()
            ->and(NodeHelper::isQueueable(firstClass("namespace App\\Jobs;\n\nclass SendMail {}")))->toBeTrue()
            ->and(NodeHelper::isMiddleware(firstClass("namespace App\\Http\\Middleware;\n\nclass Auth {}")))->toBeTrue();
    });

    it('reports the kind of each declaration', function (): void {
        expect(NodeHelper::kindOf(firstClass('class A {}')))->toBe('class')
            ->and(NodeHelper::kindOf(firstClass('interface A {}')))->toBe('interface')
            ->and(NodeHelper::kindOf(firstClass('trait A {}')))->toBe('trait')
            ->and(NodeHelper::kindOf(firstClass("enum A: string { case B = 'b'; }")))->toBe('enum');
    });

    it('lists implemented and extended interfaces', function (): void {
        expect(NodeHelper::interfaceNames(firstClass('class A implements B, C {}')))->toBe(['B', 'C'])
            ->and(NodeHelper::interfaceNames(firstClass('interface A extends B {}')))->toBe(['B'])
            ->and(NodeHelper::interfaceNames(firstClass('trait A {}')))->toBe([]);
    });
});

describe('metrics', function (): void {
    it('measures complexity, nesting and statements', function (): void {
        $method = firstMethod(<<<'PHP'
        class A
        {
            public function go(array $rows): int
            {
                $total = 0;

                foreach ($rows as $row) {
                    if ($row > 0 && $row < 100) {
                        $total += $row;
                    }
                }

                return $total;
            }
        }
        PHP);

        // 1 base + foreach + if + && = 4.
        expect(NodeHelper::cyclomaticComplexity($method))->toBe(4)
            ->and(NodeHelper::maxNestingDepth($method))->toBe(2)
            ->and(NodeHelper::countStatements($method))->toBe(5)
            ->and(NodeHelper::lineSpan($method))->toBe(12);
    });

    it('counts match arms that compute something towards complexity', function (): void {
        $method = firstMethod(<<<'PHP'
        class A
        {
            public function go(string $kind): int
            {
                return match ($kind) {
                    'a' => $this->first(),
                    'b' => 2,
                    default => 0,
                };
            }
        }
        PHP);

        expect(NodeHelper::cyclomaticComplexity($method))->toBe(4);
    });

    it('counts a lookup table as one decision however many arms it has', function (): void {
        // Every arm maps a literal to a literal: the reader checks one row,
        // not one path per arm.
        $method = firstMethod(<<<'PHP'
        class A
        {
            public function go(string $kind): int
            {
                $weight = match ($kind) {
                    'a' => 1,
                    'b' => -2,
                    'c', 'd' => self::HEAVY,
                    'e' => Status::Open,
                    'f' => ['x', 1],
                    default => null,
                };

                switch ($kind) {
                    case 'a':
                        return 1;
                    case 'b':
                    case 'c':
                        return Status::Open;
                    default:
                        return 0;
                }
            }
        }
        PHP);

        // 1 base + 1 for the match + 1 for the switch.
        expect(NodeHelper::cyclomaticComplexity($method))->toBe(3);
    });

    it('does not treat a switch that does work in its cases as a lookup table', function (): void {
        $method = firstMethod(<<<'PHP'
        class A
        {
            public function go(string $kind): void
            {
                switch ($kind) {
                    case 'a':
                        $this->a();
                        break;
                    case 'b':
                        return;
                }
            }
        }
        PHP);

        expect(NodeHelper::cyclomaticComplexity($method))->toBe(3);
    });

    it('does not treat a table with a computed key or value as a lookup table', function (): void {
        $method = firstMethod(<<<'PHP'
        class A
        {
            public function go(string $kind, int $limit): array
            {
                $row = match ($kind) {
                    'a' => ['limit' => $limit],
                    default => [],
                };

                switch ($kind) {
                    case self::prefix().'b':
                        return 1;
                }

                return $row;
            }
        }
        PHP);

        // 1 base + 2 match arms + 1 case.
        expect(NodeHelper::cyclomaticComplexity($method))->toBe(4)
            ->and(NodeHelper::lookupTableLines($method))->toBe(0);
    });

    it('measures how many lines lookup tables take up', function (): void {
        $method = firstMethod(<<<'PHP'
        class A
        {
            public function go(string $kind): int
            {
                return match ($kind) {
                    'a' => 1,
                    'b' => 2,
                    default => 0,
                };
            }
        }
        PHP);

        expect(NodeHelper::lookupTableLines($method))->toBe(5);
    });

    it('counts calls and distinct collaborators', function (): void {
        $method = firstMethod(<<<'PHP'
        class A
        {
            public function go(): void
            {
                $this->a->one();
                $this->a->two();
                $this->b->three();
                Log::info('x');
                strlen('y');
            }
        }
        PHP);

        expect(NodeHelper::countCalls($method))->toBe(5)
            ->and(NodeHelper::countDistinctCallTargets($method))->toBe(3);
    });

    it('counts only object-typed constructor parameters as dependencies', function (): void {
        $class = firstClass(<<<'PHP'
        class A
        {
            public function __construct(
                private Payments $payments,
                private string $currency,
                private array $options,
                private ?Inventory $inventory,
                private int ...$ids,
            ) {}
        }
        PHP);

        expect(NodeHelper::countDependencies($class))->toBe(2)
            ->and(NodeHelper::dependencyNames($class))->toBe(['payments', 'inventory']);
    });

    it('lists declared property names', function (): void {
        expect(NodeHelper::propertyNames(firstClass('class A { private int $a = 1; public string $b = "x", $c = "y"; }')))
            ->toBe(['a', 'b', 'c']);
    });
});

describe('traversal', function (): void {
    it('walks a fluent chain in both directions', function (): void {
        $file = parsedFile(<<<'PHP'
        class A
        {
            public function go(): mixed
            {
                return Order::where('a', 1)->with('b')->get();
            }
        }
        PHP);

        $static = NodeHelper::find($file->ast, PhpParser\Node\Expr\StaticCall::class)[0];
        $outermost = NodeHelper::outermostChain($static);

        expect(NodeHelper::chainMethodNames($outermost))->toBe(['where', 'with', 'get'])
            ->and(NodeHelper::chainRoot($outermost))->toBe($static);
    });

    it('finds the deepest nested node rather than the outermost', function (): void {
        $method = firstMethod(<<<'PHP'
        class A
        {
            public function go(array $rows): void
            {
                foreach ($rows as $row) {
                    if ($row) {
                        while ($row) {
                            $this->tick();
                        }
                    }
                }
            }
        }
        PHP);

        $deepest = NodeHelper::deepestNestedNode($method);

        expect($deepest)->toBeInstanceOf(PhpParser\Node\Stmt\While_::class)
            ->and(NodeHelper::maxNestingDepth($method))->toBe(3);
    });

    it('lists ancestors closest first', function (): void {
        $file = parsedFile(<<<'PHP'
        class A
        {
            public function go(array $rows): void
            {
                foreach ($rows as $row) {
                    $this->tick();
                }
            }
        }
        PHP);

        $call = NodeHelper::find($file->ast, PhpParser\Node\Expr\MethodCall::class)[0];
        $ancestors = NodeHelper::ancestors($call);

        expect($ancestors)->not->toBeEmpty()
            ->and($ancestors[0])->toBeInstanceOf(PhpParser\Node\Stmt\Expression::class)
            ->and(NodeHelper::closestAncestor($call, ClassLike::class))->toBeInstanceOf(ClassLike::class);
    });

    it('detects anything that could reach a member dynamically', function (): void {
        expect(NodeHelper::hasDynamicAccess(firstClass('class A { public function go(): void { $x = compact("a"); } }')))->toBeTrue()
            ->and(NodeHelper::hasDynamicAccess(firstClass('class A { public function go(string $n): mixed { return $this->{$n}; } }')))->toBeTrue()
            ->and(NodeHelper::hasDynamicAccess(firstClass('class A { public function go(): mixed { return $this->b; } }')))->toBeFalse();
    });
});

describe('structural hashing', function (): void {
    it('ignores variable names and literal values', function (): void {
        $a = firstMethod('class A { public function go(): int { $count = 1; return $count + 2; } }');
        $b = firstMethod('class B { public function go(): int { $total = 9; return $total + 7; } }');

        expect(NodeHelper::structuralHash($a))->toBe(NodeHelper::structuralHash($b));
    });

    it('keeps the names of methods that are called', function (): void {
        $a = firstMethod('class A { public function go(): mixed { return $this->x->charge(1); } }');
        $b = firstMethod('class B { public function go(): mixed { return $this->x->refund(1); } }');

        expect(NodeHelper::structuralHash($a))->not->toBe(NodeHelper::structuralHash($b));
    });

    it('treats the class a static call targets as a parameter', function (): void {
        $a = firstMethod('class A { public function go(): mixed { return Order::find(1); } }');
        $b = firstMethod('class B { public function go(): mixed { return Invoice::find(1); } }');

        expect(NodeHelper::structuralHash($a))->toBe(NodeHelper::structuralHash($b));
    });

    it('distinguishes different control flow', function (): void {
        $a = firstMethod('class A { public function go(array $r): void { foreach ($r as $x) { $this->go2($x); } } }');
        $b = firstMethod('class B { public function go(array $r): void { if ($r) { $this->go2($r); } } }');

        expect(NodeHelper::structuralHash($a))->not->toBe(NodeHelper::structuralHash($b));
    });
});

describe('printing and names', function (): void {
    it('prints an expression as one normalised line', function (): void {
        $file = parsedFile('class A { public function go(array $w): bool { return isset($w["a"]) && $w["b"]; } }');
        $condition = NodeHelper::find($file->ast, PhpParser\Node\Expr\BinaryOp\BooleanAnd::class)[0];

        // The printer preserves the source's own quote style, which is what
        // makes two identical conditions compare equal.
        expect(NodeHelper::printAny($condition))->toBe('isset($w["a"]) && $w["b"]');
    });

    it('reduces a fully qualified name to its last segment', function (): void {
        expect(NodeHelper::baseName('App\Models\Order'))->toBe('Order')
            ->and(NodeHelper::baseName('Order'))->toBe('Order');
    });

    it('compares names case insensitively', function (): void {
        expect(NodeHelper::isNameOneOf('Get', ['get', 'first']))->toBeTrue()
            ->and(NodeHelper::isNameOneOf('save', ['get', 'first']))->toBeFalse()
            ->and(NodeHelper::isNameOneOf(null, ['get']))->toBeFalse();
    });

    it('renders parameter types, including unions and nullables', function (): void {
        $method = firstMethod('class A { public function go(?Order $a, Order|Invoice $b, int $c, $d): void {} }');
        $params = $method->params;

        expect(NodeHelper::typeToString($params[0]->type))->toBe('?Order')
            ->and(NodeHelper::typeToString($params[1]->type))->toBe('Order|Invoice')
            ->and(NodeHelper::typeToString($params[2]->type))->toBe('int')
            ->and(NodeHelper::typeToString($params[3]->type))->toBeNull();
    });

    it('maps variables to the expression that produced them', function (): void {
        $method = firstMethod('class A { public function go(): void { $a = Order::all(); $a = Invoice::all(); } }');
        $assignments = NodeHelper::assignedVariables($method);

        // The last assignment wins, which is what "where did this come from"
        // callers want.
        expect(array_keys($assignments))->toBe(['a'])
            // Class names print fully qualified, because the parser resolved them.
            ->and(NodeHelper::printAny($assignments['a']))->toBe('\Invoice::all()');
    });

    it('separates public methods from the rest', function (): void {
        $class = firstClass('class A { public function a(): void {} protected function b(): void {} private function c(): void {} }');

        expect(NodeHelper::methods($class))->toHaveCount(3)
            ->and(NodeHelper::publicMethods($class))->toHaveCount(1)
            ->and(NodeHelper::constructor($class))->toBeNull();
    });
});
