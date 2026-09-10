<?php

namespace App\Services;

use App\Models\Invoice;

interface InvoiceServiceInterface
{
    public function get(int $id): ?Invoice;
}
