<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An ordinary Eloquent model: many small members, all of them the kind a model
 * is meant to have. SL102 and SL209 should both leave this alone.
 */
final class Product extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'sku', 'price_cents', 'category_id'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class);
    }

    public function price(): Attribute
    {
        return Attribute::get(fn (): string => number_format($this->price_cents / 100, 2));
    }

    public function slug(): Attribute
    {
        return Attribute::get(fn (): string => str($this->name)->slug()->value());
    }

    public function scopeInStock($query)
    {
        return $query->whereHas('inventory', fn ($q) => $q->where('available', '>', 0));
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }

    public function scopeInCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'price_cents' => 'integer',
        ];
    }
}
