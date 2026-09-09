<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessImportSource extends Model
{
    protected $fillable = ['provider', 'source_id', 'page_id', 'url', 'metadata'];

    protected function casts(): array
    {
        return ['page_id' => 'integer', 'metadata' => 'array'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
