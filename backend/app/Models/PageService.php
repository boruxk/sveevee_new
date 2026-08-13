<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PageService extends Model
{
    protected $fillable = [
        'page_id',
        'name',
        'description',
        'category_key',
        'image_path',
        'image_original_name',
        'link',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path ? url(Storage::url($this->image_path)) : null);
    }
}
