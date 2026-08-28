<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuestSupportConversation extends Model
{
    protected $fillable = [
        'token_hash',
        'name',
        'email',
        'locale',
        'last_message_at',
        'claimed_by_user_id',
        'claimed_conversation_id',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(GuestSupportMessage::class)->oldest();
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function claimedConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'claimed_conversation_id');
    }
}
