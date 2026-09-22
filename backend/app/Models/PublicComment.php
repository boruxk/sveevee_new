<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicComment extends Model
{
    protected $fillable = ['target_type', 'target_id', 'user_id', 'parent_id', 'recommended_page_id', 'body'];

    protected function casts(): array
    {
        return ['helpful' => 'boolean', 'hidden_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function recommendedPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'recommended_page_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('public_comments.hidden_at')
            ->whereHas('user', fn ($q) => $q->whereNull('banned_at'))
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhereHas('parent', fn ($p) => $p
                ->whereNull('hidden_at')->whereHas('user', fn ($u) => $u->whereNull('banned_at'))));
    }
}
