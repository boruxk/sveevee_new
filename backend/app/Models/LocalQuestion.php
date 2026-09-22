<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalQuestion extends Model
{
    protected $attributes = ['status' => 'open'];

    protected $fillable = ['user_id', 'title', 'body', 'category_key', 'city', 'neighborhood', 'status'];

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('hidden_at')->whereHas('user', fn ($q) => $q->whereNull('banned_at'));
    }
}
