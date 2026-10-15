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

    public function refundWithDetails(
        int $paymentId,
        int $cents,
        string $currency,
        string $reason,
        string $requestedBy,
        string $approvedBy,
        bool $notifyCustomer,
        string $channel,
    ): bool {
        $payment = Payment::findOrFail($paymentId);

        return $payment->markRefunded($cents);
    }
}
