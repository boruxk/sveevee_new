<?php

namespace App\Models;

use App\Support\PublicSlug;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PageProduct extends Model
{
    protected $fillable = [
        'page_id',
        'name',
        'description',
        'category_key',
        'image_path',
        'image_original_name',
        'price',
        'link',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
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
