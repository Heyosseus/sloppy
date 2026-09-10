<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Laravel\BusinessLogicInControllerRule;

/**
 * @param  array<string, mixed>  $options
 */
function businessLogic(array $options = []): BusinessLogicInControllerRule
{
    return new BusinessLogicInControllerRule($options);
}

it('flags an action that writes, calculates, transacts and notifies', function (): void {
    $found = findings(businessLogic(), <<<'PHP'
    namespace App\Http\Controllers;

    class OrderController extends Controller
    {
        public function store(Request $request)
        {
            DB::beginTransaction();

            $order = Order::create(['customer_id' => $request->customer_id]);
            $subtotal = 0;

            foreach ($request->items as $line) {
                $subtotal = $subtotal + $line['price'] * $line['qty'];
                $order->items()->create($line);
            }

            $order->total = $subtotal + $subtotal * 0.2;
            $order->save();

            DB::commit();

            Mail::to($order->email)->send(new OrderPlaced($order));

            return response()->json($order);
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL201')
        ->and($found[0]->fingerprint)->toBe('OrderController::store')
        ->and($found[0]->metrics['score'])->toBeGreaterThanOrEqual(6)
        ->and($found[0]->message)->toContain('database writes');
});

it('leaves a thin action that delegates alone', function (): void {
    expect(findings(businessLogic(), <<<'PHP'
    namespace App\Http\Controllers;

    class OrderController extends Controller
    {
        public function store(PlaceOrderRequest $request)
        {
            $order = $this->placeOrder->handle($request->user(), $request->lines());

            return OrderResource::make($order)->response()->setStatusCode(201);
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a normal index action that reads and paginates', function (): void {
    expect(findings(businessLogic(), <<<'PHP'
    namespace App\Http\Controllers;

    class OrderController extends Controller
    {
        public function index(Request $request)
        {
            $orders = Order::query()
                ->with(['customer', 'items'])
                ->when($request->status, fn ($q, $status) => $q->where('status', $status))
                ->latest()
                ->paginate(25);

            return OrderResource::collection($orders)->response();
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a single write on its own', function (): void {
    expect(findings(businessLogic(), <<<'PHP'
    namespace App\Http\Controllers;

    class OrderController extends Controller
    {
        public function update(UpdateOrderRequest $request, Order $order)
        {
            $order->update($request->validated());
            $order->refresh();
            $order->loadMissing('items');

            return OrderResource::make($order)->response();
        }
    }
    PHP))->toBeEmpty();
});

it('ignores classes that are not controllers', function (): void {
    expect(findings(businessLogic(), <<<'PHP'
    namespace App\Actions;

    class PlaceOrder
    {
        public function handle(Request $request)
        {
            DB::beginTransaction();

            $order = Order::create([]);
            $subtotal = 0;

            foreach ($request->items as $line) {
                $subtotal = $subtotal + $line['price'] * $line['qty'];
                $order->items()->create($line);
            }

            $order->save();

            DB::commit();

            Mail::to($order->email)->send(new OrderPlaced($order));

            return $order;
        }
    }
    PHP))->toBeEmpty();
});

it('respects the configured score threshold', function (): void {
    $code = <<<'PHP'
    namespace App\Http\Controllers;

    class OrderController extends Controller
    {
        public function store(Request $request)
        {
            $order = Order::create([]);
            $order->items()->create([]);
            $order->save();
            $order->refresh();
            $total = 1 + 2 + 3 * 4;
            $order->total = $total;
            $order->save();

            return $order;
        }
    }
    PHP;

    expect(findings(businessLogic(['min_score' => 100]), $code))->toBeEmpty()
        ->and(findings(businessLogic(['min_score' => 2]), $code))->toHaveCount(1);
});
