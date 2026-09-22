<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunitySubscription extends Model
{
    protected $fillable = ['user_id', 'page_id', 'category_key', 'city', 'neighborhood', 'scope_key', 'notifications_enabled'];

    protected function casts(): array
    {
        return ['notifications_enabled' => 'boolean'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
