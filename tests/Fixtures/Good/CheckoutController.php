<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\PlaceOrder;
use App\Http\Requests\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * Everything a controller is supposed to look like, so the rules have
 * something to stay quiet about.
 */
final class CheckoutController extends Controller
{
    public function __construct(private readonly PlaceOrder $placeOrder) {}

    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $order = $this->placeOrder->handle($request->user(), $request->lines());

        return OrderResource::make($order)->response()->setStatusCode(201);
    }

    public function index(): JsonResponse
    {
        // Eager loading here keeps the resource from issuing a query per row.
        $orders = Order::query()
            ->with(['customer', 'items.product'])
            ->latest()
            ->paginate(25);

        return OrderResource::collection($orders)->response();
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['customer', 'items']);

        return OrderResource::make($order)->response();
    }

    public function destroy(Order $order): JsonResponse
    {
        $order->delete();

        return new JsonResponse(status: 204);
    }
}
