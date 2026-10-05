<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Internal;

/**
 * Private to the Payments integration: nothing outside it should name this.
 */
final readonly class Signer
{
    public function __construct(private string $secret) {}

    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
