<?php

declare(strict_types=1);

use App\Http\Controllers\OrderController;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/orders', [OrderController::class, 'index']);

// SL211: the agent routed to the method it meant to write next.
Route::get('/orders/export', [OrderController::class, 'exportCsv']);

// SL213: a link that deletes.
Route::get('/orders/{order}/delete', [OrderController::class, 'destroy']);

// SL212: a controller action with no controller.
Route::post('/orders/bulk-status', function (Request $request) {
    $data = $request->validate([
        'ids' => 'required|array',
        'status' => 'required|string',
    ]);

    Order::whereIn('id', $data['ids'])->update(['status' => $data['status']]);

    return back();
});
