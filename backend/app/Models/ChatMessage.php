<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'read_at',
        'is_automatic',
    ];

    protected $attributes = ['is_automatic' => false];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'is_automatic' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('conversation', fn (Builder $conversation) => $conversation
            ->where(function (Builder $participants) use ($user): void {
                foreach (['one', 'two'] as $side) {
                    $participants->orWhere(fn (Builder $participant) => $participant
                        ->where("user_{$side}_id", $user->id)
                        ->where(fn (Builder $visible) => $visible
                            ->whereNull("user_{$side}_cleared_message_id")
                            ->orWhereColumn('chat_messages.id', '>', "conversations.user_{$side}_cleared_message_id")));
                }
            }));
    }
}
