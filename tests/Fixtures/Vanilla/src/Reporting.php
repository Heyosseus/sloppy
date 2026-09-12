<?php

declare(strict_types=1);

namespace Vanilla;

use RuntimeException;
use Throwable;

final class Reporting
{
    /**
     * Deliberately sloppy: this fixture exists to be reported on.
     */
    public function build(array $rows): array
    {
        $out = [];
        $total = 0;
        $count = 0;
        $taxTotal = 0;
        $discountTotal = 0;
        $netTotal = 0;

        foreach ($rows as $row) {
            $amount = (int) ($row['amount'] ?? 0);
            $tax = (int) ($row['tax'] ?? 0);
            $discount = (int) ($row['discount'] ?? 0);
            $net = $amount + $tax - $discount;

            $total = $total + $amount;
            $taxTotal = $taxTotal + $tax;
            $discountTotal = $discountTotal + $discount;
            $netTotal = $netTotal + $net;
            $count = $count + 1;

            $label = (string) ($row['label'] ?? '');
            $trimmed = trim($label);
            $upper = strtoupper($trimmed);
            $lower = strtolower($trimmed);
            $slug = str_replace(' ', '-', $lower);
            $slug = str_replace('_', '-', $slug);
            $slug = str_replace('.', '-', $slug);
            $slug = trim($slug, '-');

            $currency = (string) ($row['currency'] ?? 'USD');
            $currency = strtoupper(trim($currency));
            $category = (string) ($row['category'] ?? 'uncategorised');
            $category = strtolower(trim($category));

            $formattedAmount = number_format($amount / 100, 2);
            $formattedTax = number_format($tax / 100, 2);
            $formattedDiscount = number_format($discount / 100, 2);
            $formattedNet = number_format($net / 100, 2);

            $out[] = [
                'label' => $upper,
                'slug' => $slug,
                'amount' => $amount,
                'tax' => $tax,
                'discount' => $discount,
                'net' => $net,
                'currency' => $currency,
                'category' => $category,
                'formatted_amount' => $formattedAmount,
                'formatted_tax' => $formattedTax,
                'formatted_discount' => $formattedDiscount,
                'formatted_net' => $formattedNet,
            ];
        }

        $average = $count === 0 ? 0 : intdiv($total, $count);
        $averageTax = $count === 0 ? 0 : intdiv($taxTotal, $count);
        $averageDiscount = $count === 0 ? 0 : intdiv($discountTotal, $count);
        $averageNet = $count === 0 ? 0 : intdiv($netTotal, $count);

        $out[] = [
            'label' => 'AVERAGE',
            'slug' => 'average',
            'amount' => $average,
            'tax' => $averageTax,
            'discount' => $averageDiscount,
            'net' => $averageNet,
            'currency' => 'USD',
            'category' => 'summary',
            'formatted_amount' => number_format($average / 100, 2),
            'formatted_tax' => number_format($averageTax / 100, 2),
            'formatted_discount' => number_format($averageDiscount / 100, 2),
            'formatted_net' => number_format($averageNet / 100, 2),
        ];

        try {
            $this->persist($out);
        } catch (Throwable $exception) {
        }

        return $out;
    }

    private function persist(array $rows): void
    {
        throw new RuntimeException('not implemented');
    }
}
