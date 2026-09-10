<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\NotificationService;
use App\Services\PaymentGateway;
use App\Services\PricingService;
use App\Services\ShippingCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    public function __construct(
        private PaymentGateway $payments,
        private InventoryService $inventory,
        private NotificationService $notifications,
        private PricingService $pricing,
        private ShippingCalculator $shipping,
        private AuditLogger $audit,
    ) {}

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:100',
            'coupon_code' => 'nullable|string|max:32',
            'shipping_address' => 'required|array',
            'shipping_address.line_1' => 'required|string|max:255',
            'shipping_address.postcode' => 'required|string|max:16',
            'payment_method' => 'required|string|in:card,paypal,transfer',
        ]);

        // Check if customer exists
        if (! $validated['customer_id']) {
            return null;
        }

        if ($validated['customer_id'] === null) {
            return null;
        }

        $subtotal = 0;
        $taxTotal = 0;
        $discount = 0;

        DB::beginTransaction();

        $order = Order::create([
            'customer_id' => $validated['customer_id'],
            'status' => 'pending',
        ]);

        foreach ($validated['items'] as $line) {
            $product = Product::where('id', $line['product_id'])->first();

            if ($product) {
                if ($product->stock_level > 0) {
                    $stock = Inventory::where('product_id', $product->id)->first();

                    if ($stock) {
                        try {
                            if ($stock->available >= $line['quantity']) {
                                $stock->available = $stock->available - $line['quantity'];
                                $stock->save();
                            }
                        } catch (\Throwable $e) {
                            return null;
                        }
                    }
                }
            }

            $lineTotal = $product->price * $line['quantity'];
            $lineTax = $lineTotal * 0.2;
            $subtotal = $subtotal + $lineTotal;
            $taxTotal = $taxTotal + $lineTax;

            $order->items()->create([
                'product_id' => $line['product_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $product->price,
            ]);
        }

        if ($validated['coupon_code']) {
            $response = Http::post('https://coupons.example.com/redeem', [
                'code' => $validated['coupon_code'],
                'order_id' => $order->id,
            ]);

            if ($response->successful()) {
                $discount = $response->json('discount_cents') / 100;
            }
        }

        $order->subtotal = $subtotal;
        $order->tax = $taxTotal;
        $order->discount = $discount;
        $order->total = $subtotal + $taxTotal - $discount;
        $order->save();

        $charge = $this->payments->charge($order->total, $validated['payment_method']);

        if (! $charge->successful) {
            DB::rollBack();

            return response()->json(['error' => 'Payment failed'], 422);
        }

        DB::commit();

        Mail::to($order->customer_email)->send(new \App\Mail\OrderPlaced($order));
        $this->notifications->notifyWarehouse($order);

        return response()->json(['id' => $order->id, 'total' => $order->total]);
    }

    public function index(Request $request)
    {
        $orders = Order::all();

        $rows = [];

        foreach ($orders as $order) {
            $rows[] = [
                'id' => $order->id,
                'customer' => $order->customer->name,
                'items' => $order->items()->count(),
                'shipped_by' => $order->shipment->carrier->name,
            ];
        }

        return response()->json($rows);
    }

    public function report(Request $request)
    {
        $total = 0;

        foreach (Order::all() as $order) {
            $payments = DB::table('payments')->where('order_id', $order->id)->get();

            foreach ($payments as $payment) {
                $total = $total + $payment->amount;
            }
        }

        return response()->json(['total' => $total]);
    }
}
