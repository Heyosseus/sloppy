<?php

namespace App\Billing;

class RefundProcessor
{
    public function refund(int $paymentId, int $cents): bool
    {
        $payment = Payment::findOrFail($paymentId);

        // ... existing code ...

        return $payment->markRefunded($cents);
    }

    public function partialRefund(int $paymentId, int $cents): bool
    {
        throw new \RuntimeException('Not implemented yet');
    }

    public function pendingRefunds(): array
    {
        // TODO: query the refunds table
        return [];
    }
}
