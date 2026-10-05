<?php

declare(strict_types=1);

namespace App\Integrations\Shipping;

use App\Integrations\Payments\Internal\Signer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * One integration reaching into another's internals instead of its client
 * (SL306).
 */
final readonly class ShippingClient
{
    public function __construct(private Signer $signer) {}

    public function book(string $parcel): Response
    {
        return Http::withHeaders(['Signature' => $this->signer->sign($parcel)])->post('https://courier.test/book', ['parcel' => $parcel]);
    }
}
