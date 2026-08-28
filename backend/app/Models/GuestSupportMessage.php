<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestSupportMessage extends Model
{
    public const SENDER_GUEST = 'guest';

    public const SENDER_ADMIN = 'admin';

    protected $fillable = [
        'guest_support_conversation_id',
        'sender_type',
        'sender_user_id',
        'body',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(GuestSupportConversation::class, 'guest_support_conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
