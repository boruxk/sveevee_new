<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PageConversation extends Model
{
    protected $fillable = [
        'page_id',
        'visitor_id',
        'last_message_at',
        'guest_token_hash',
        'guest_claimed_by_user_id',
        'guest_claimed_conversation_id',
        'guest_claimed_at',
    ];

    protected $hidden = [
        'guest_token_hash',
        'guest_claimed_by_user_id',
        'guest_claimed_conversation_id',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'guest_claimed_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'visitor_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PageChatMessage::class)->oldest()->orderBy('id');
    }
}
