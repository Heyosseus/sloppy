<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Integrations\Payments\PaymentClient;
use Illuminate\Http\JsonResponse;

/**
 * A controller that talks to a payment provider itself and reads the
 * environment: both forbidden by the corpus policy (SL304, SL305).
 */
final class ReportController extends Controller
{
    public function __construct(private readonly PaymentClient $payments) {}

    public function refund(string $chargeId): JsonResponse
    {
        $this->payments->refund($chargeId);

        return new JsonResponse(['mode' => env('PAYMENTS_MODE')]);
    }
}
