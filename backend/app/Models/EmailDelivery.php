<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailDelivery extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SOFT_BOUNCED = 'soft_bounced';

    public const STATUS_HARD_BOUNCED = 'hard_bounced';

    protected $fillable = [
        'user_id',
        'conversation_id',
        'chat_message_id',
        'kind',
        'recipient_email',
        'bounce_token',
        'status',
        'attempts',
        'failure_reason',
        'sent_at',
        'bounced_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'bounced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class);
    }
}
