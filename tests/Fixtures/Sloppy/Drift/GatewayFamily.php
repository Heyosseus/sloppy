<?php

declare(strict_types=1);

namespace App\Actions\Payment;

class GatewayFamily
{
    public function chargeFirst(Order $order): string
    {
        $gateway = new StripeGateway();
        $token = $gateway->authorise($order->customer);
        $response = $gateway->charge($token, $order->total, $order->currency);
        $this->log->info('charged', ['order' => $order->id]);
        $order->markPaid($response->reference);
        $this->metrics->increment('charges');
        $this->metrics->gauge('charge_amount', $order->total);

        return $response->reference;
    }

    public function chargeSecond(Order $order): string
    {
        $gateway = new StripeGateway();
        $token = $gateway->authorise($order->customer);
        $response = $gateway->charge($token, $order->total, $order->currency);
        $this->log->info('charged', ['order' => $order->id]);
        $order->markPaid($response->reference);
        $this->metrics->increment('charges');
        $this->metrics->gauge('charge_amount', $order->total);

        return $response->reference;
    }

    public function chargeThird(Order $order): string
    {
        $gateway = new PaypalGateway();
        $token = $gateway->authorise($order->customer);
        $response = $gateway->charge($token, $order->total, $order->currency);
        $this->log->info('charged', ['order' => $order->id]);
        $order->markPaid($response->reference);
        $this->metrics->increment('charges');
        $this->metrics->gauge('charge_amount', $order->total);

        return $response->reference;
    }
}
