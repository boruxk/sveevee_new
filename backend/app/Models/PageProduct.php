<?php

namespace App\Models;

use App\Support\PublicSlug;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PageProduct extends Model
{
    protected $touches = ['page'];

    protected $fillable = [
        'page_id',
        'name',
        'brand',
        'model',
        'description',
        'category_key',
        'image_path',
        'image_original_name',
        'price',
        'offer_enabled',
        'offer_price',
        'offer_starts_at',
        'offer_ends_at',
        'previous_price',
        'views_count',
        'contacts_count',
        'link',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'offer_enabled' => 'boolean',
            'offer_price' => 'decimal:2',
            'offer_starts_at' => 'datetime',
            'offer_ends_at' => 'datetime',
            'previous_price' => 'decimal:2',
            'views_count' => 'integer',
            'contacts_count' => 'integer',
        ];
    }

    public function hasActiveOffer(): bool
    {
        if (! $this->offer_enabled || $this->offer_price === null || ! $this->offer_starts_at || ! $this->offer_ends_at) {
            return false;
        }

        return now()->betweenIncluded($this->offer_starts_at, $this->offer_ends_at);
    }

    public function currentPrice(): float
    {
        return (float) ($this->hasActiveOffer() ? $this->offer_price : $this->price);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path ? url(Storage::url($this->image_path)) : null);
    }

    protected function publicSlug(): Attribute
    {
        return Attribute::get(fn (): string => PublicSlug::make([
            $this->name,
            data_get($this->page?->setup, 'address.city'),
        ], 'product', $this->id));
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $candidate = (string) $value;

        $id = PublicSlug::idFromSlug($candidate);

        return $id ? $this->whereKey($id)->first() : null;
    }
}
