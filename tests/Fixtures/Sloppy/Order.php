<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

class Order extends Model
{
    protected $fillable = ['customer_id', 'status', 'total'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function getFormattedTotalAttribute(): string
    {
        return number_format($this->total / 100, 2);
    }

    public function fulfil(): void
    {
        $this->status = 'fulfilled';
        $this->save();

        $response = Http::post('https://warehouse.example.com/dispatch', [
            'order_id' => $this->id,
            'items' => $this->items->pluck('sku')->all(),
        ]);

        if ($response->failed()) {
            $this->status = 'dispatch_failed';
            $this->save();
        }

        Notification::send($this->customer, new \App\Notifications\OrderFulfilled($this));

        foreach ($this->items as $item) {
            $stock = Inventory::where('product_id', $item->product_id)->first();

            if ($stock) {
                $stock->decrement('available', $item->quantity);
            }
        }
    }
}
