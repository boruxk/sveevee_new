<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageChatMessage extends Model
{
    protected $fillable = [
        'page_conversation_id',
        'sender_id',
        'sender_as_page',
        'body',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'sender_as_page' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(PageConversation::class, 'page_conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
