<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Customer;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A service that coordinates real work, with dependencies it uses, guards that
 * each mean something, and a catch block that records what went wrong.
 */
final readonly class PlaceOrder
{
    public function __construct(
        private OrderPricing $pricing,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: int}>  $lines
     */
    public function handle(Customer $customer, array $lines): Order
    {
        if ($lines === []) {
            throw new EmptyBasket();
        }

        $price = $this->pricing->for($customer, $lines);

        return DB::transaction(fn (): Order => $this->persist($customer, $lines, $price));
    }

    public function cancel(Order $order): void
    {
        try {
            $order->refund();
        } catch (Throwable $exception) {
            // The order stays cancellable, but a failed refund must be visible.
            $this->logger->error('Refund failed', ['order' => $order->id, 'exception' => $exception]);

            throw new RefundFailed(previous: $exception);
        }
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $lines
     */
    private function persist(Customer $customer, array $lines, Money $price): Order
    {
        $order = $customer->orders()->create(['total' => $price->cents]);

        $order->items()->createMany($lines);

        return $order;
    }
}
