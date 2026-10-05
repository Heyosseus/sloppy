<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;

/**
 * A use case with a second public entry point and no `final`: the corpus
 * policy gives services one shape (SL308).
 */
class RefundOrder
{
    public function handle(Order $order): void
    {
        $order->refund();
    }

    public function audit(Order $order): string
    {
        return 'Refunded order '.$order->id;
    }
}
