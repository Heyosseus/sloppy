<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP calls belong somewhere. SL208 must not flag the class that exists to be
 * that somewhere.
 */
final readonly class PaymentClient
{
    public function __construct(private string $endpoint, private string $token) {}

    public function charge(int $cents, string $reference): Response
    {
        return Http::withToken($this->token)
            ->timeout(10)
            ->retry(2, 250)
            ->post($this->endpoint.'/charges', [
                'amount' => $cents,
                'reference' => $reference,
            ]);
    }

    public function refund(string $chargeId): Response
    {
        return Http::withToken($this->token)
            ->timeout(10)
            ->post($this->endpoint.'/refunds', ['charge' => $chargeId]);
    }
}
