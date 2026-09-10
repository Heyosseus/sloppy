<?php

namespace App\Services;

use App\Models\Invoice;
use App\Repositories\InvoiceRepositoryInterface;

class InvoiceService implements InvoiceServiceInterface
{
    public function __construct(private InvoiceRepositoryInterface $repository) {}

    public function get(int $id): ?Invoice
    {
        return $this->repository->find($id);
    }
}
