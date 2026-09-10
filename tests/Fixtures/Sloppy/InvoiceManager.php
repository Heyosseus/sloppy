<?php

namespace App\Services;

use App\Models\Invoice;

class InvoiceManager
{
    public function __construct(private InvoiceServiceInterface $service) {}

    public function get(int $id): ?Invoice
    {
        return $this->service->get($id);
    }

    public function require(int $id): Invoice
    {
        return $this->service->get($id);
    }
}
