<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatEmailNotificationState extends Model
{
    protected $fillable = [
        'conversation_id',
        'recipient_id',
        'pending_message_id',
        'pending_token',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function pendingMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'pending_message_id');
    }
}
