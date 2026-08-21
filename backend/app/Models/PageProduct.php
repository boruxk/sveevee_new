<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        return Attribute::get(function (): string {
            $nameSlug = Str::slug((string) $this->name);
            $citySlug = Str::slug((string) data_get($this->page?->setup, 'address.city'));
            $parts = array_filter([$nameSlug !== '' ? $nameSlug : 'product', $citySlug]);

            return implode('-', $parts).'-'.$this->id;
        });
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $candidate = (string) $value;

        if (ctype_digit($candidate)) {
            return $this->whereKey($candidate)->first();
        }

        if (preg_match('/-(\d+)$/', $candidate, $matches)) {
            return $this->whereKey($matches[1])->first();
        }

        return null;
    }
}
