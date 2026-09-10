<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use Psr\Log\LoggerInterface;

class ReportingService
{
    public function __construct(
        private LoggerInterface $logger,
        private CurrencyConverter $converter,
    ) {}

    public function monthlyOrderTotals(int $year, int $month): array
    {
        $rows = [];
        $total = 0;

        $orders = Order::where('year', $year)->where('month', $month)->get();

        foreach ($orders as $order) {
            $amount = $this->converter->toBase($order->total, $order->currency);
            $total = $total + $amount;

            $rows[] = [
                'id' => $order->id,
                'amount' => $amount,
                'currency' => $order->currency,
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    public function monthlyInvoiceTotals(int $year, int $month): array
    {
        $rows = [];
        $total = 0;

        $invoices = Invoice::where('year', $year)->where('month', $month)->get();

        foreach ($invoices as $invoice) {
            $amount = $this->converter->toBase($invoice->total, $invoice->currency);
            $total = $total + $amount;

            $rows[] = [
                'id' => $invoice->id,
                'amount' => $amount,
                'currency' => $invoice->currency,
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    public function activeCustomerCount(): int
    {
        return \App\Models\Customer::all()->where('active', true)->count();
    }

    public function summarise(?array $window): ?string
    {
        if ($window === null) {
            return null;
        }

        if (! $window) {
            return null;
        }

        if (isset($window['from']) && isset($window['from'])) {
            return $window['from'];
        }

        return null;
    }

    private function formatLegacyRow(array $row): string
    {
        return implode(',', $row);
    }
}
