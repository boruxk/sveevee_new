<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageIdentityKey extends Model
{
    protected $fillable = [
        'page_id',
        'type',
        'category_key',
        'normalized_name',
        'normalized_city',
        'normalized_neighborhood',
        'normalized_phone',
        'normalized_website',
        'normalized_address',
        'identity_hash',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
