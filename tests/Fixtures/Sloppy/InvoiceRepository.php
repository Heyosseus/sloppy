<?php

namespace App\Repositories;

use App\Models\Invoice;

class InvoiceRepository implements InvoiceRepositoryInterface
{
    public function find(int $id): ?Invoice
    {
        return Invoice::find($id);
    }
}
