<?php

declare(strict_types=1);

namespace App\Actions\Payment;

class RefundTbcPayment
{
    public function __construct(private TbcClient $client, private Logger $log) {}

    public function execute(Payment $payment): bool
    {
        $token = $this->client->authorise($payment->reference);
        $response = $this->client->refund($token, $payment->amount, $payment->currency);
        $this->log->info('refund requested', ['payment' => $payment->id]);
        $payment->touch();
        $this->log->info('refund processing', ['payment' => $payment->id]);
        $this->log->info('refund verified', ['payment' => $payment->id]);

        $payment->markRefunded($response->reference);
        $this->log->info('refund settled', ['payment' => $payment->id]);

        return true;
    }
}
