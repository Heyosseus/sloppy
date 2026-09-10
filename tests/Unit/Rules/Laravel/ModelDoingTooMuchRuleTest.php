<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Laravel\ModelDoingTooMuchRule;

/**
 * @param  array<string, mixed>  $options
 */
function overreachingModel(array $options = []): ModelDoingTooMuchRule
{
    return new ModelDoingTooMuchRule($options);
}

it('flags a model that calls out and notifies', function (): void {
    $found = findings(overreachingModel(), <<<'PHP'
    class Order extends Model
    {
        public function fulfil(): void
        {
            Http::post('https://warehouse.example.com/dispatch', ['id' => $this->id]);
            Notification::send($this->customer, new OrderFulfilled($this));
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL209')
        ->and($found[0]->fingerprint)->toBe('Order')
        ->and($found[0]->metrics['http_calls'])->toBe(1)
        ->and($found[0]->metrics['dispatches'])->toBe(1);
});

it('leaves relations, scopes, accessors and casts alone', function (): void {
    expect(findings(overreachingModel(), <<<'PHP'
    class Product extends Model
    {
        public function category(): BelongsTo
        {
            return $this->belongsTo(Category::class);
        }

        public function variants(): HasMany
        {
            return $this->hasMany(ProductVariant::class);
        }

        public function price(): Attribute
        {
            return Attribute::get(fn (): string => number_format($this->price_cents / 100, 2));
        }

        public function scopeInStock($query)
        {
            return $query->where('available', '>', 0);
        }

        public function getLabelAttribute(): string
        {
            return $this->name.' ('.$this->sku.')';
        }

        protected function casts(): array
        {
            return ['published_at' => 'datetime'];
        }
    }
    PHP))->toBeEmpty();
});

it('ignores non-models', function (): void {
    expect(findings(overreachingModel(), <<<'PHP'
    class OrderService
    {
        public function fulfil(Order $order): void
        {
            Http::post('https://warehouse.example.com/dispatch', ['id' => $order->id]);
            Notification::send($order->customer, new OrderFulfilled($order));
        }
    }
    PHP))->toBeEmpty();
});

it('does not count event wiring in boot as overreach', function (): void {
    // Registering model events in booted() is where Laravel itself puts them.
    expect(findings(overreachingModel(), <<<'PHP'
    class Order extends Model
    {
        protected static function booted(): void
        {
            static::created(function (Order $order): void {
                event(new OrderCreated($order));
            });
        }
    }
    PHP))->toBeEmpty();
});

it('flags a long business workflow on a model', function (): void {
    $body = implode("\n", array_map(
        static fn (int $i): string => sprintf('        $step%d = $this->calculate(%d);', $i, $i),
        range(1, 20),
    ));

    $found = findings(overreachingModel(['max_method_lines' => 15]), <<<PHP
    class Order extends Model
    {
        public function reconcile(): void
        {
    $body
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['long_methods'])->toBe(1)
        ->and($found[0]->message)->toContain('reconcile()');
});

it('can require more than one signal', function (): void {
    expect(findings(overreachingModel(['min_signals' => 2]), <<<'PHP'
    class Order extends Model
    {
        public function ping(): void
        {
            Http::get('https://status.example.com');
        }
    }
    PHP))->toBeEmpty();
});
