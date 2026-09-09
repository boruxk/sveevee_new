<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessImportCity extends Model
{
    public $timestamps = false;

    protected $fillable = ['provider', 'value_hash', 'raw_value', 'first_source_id', 'example_page_id', 'first_seen_at', 'last_seen_at', 'mapped_city'];

    protected function casts(): array
    {
        return ['example_page_id' => 'integer', 'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }

    public function examplePage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'example_page_id');
    }
}
