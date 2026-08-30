<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiWorkPreference extends Model
{
    protected $fillable = ['user_id', 'page_defaults'];

    protected function casts(): array
    {
        return ['page_defaults' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
