<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'user_one_id',
        'user_two_id',
        'started_by_user_id',
        'is_support',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'is_support' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->oldest();
    }

    public function scopeForParticipant(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $inner) use ($user): void {
            $inner->where('user_one_id', $user->id)->orWhere('user_two_id', $user->id);
        });
    }

    public function otherParticipant(User $user): ?User
    {
        if ($this->user_one_id === $user->id) {
            return $this->userTwo;
        }

        if ($this->user_two_id === $user->id) {
            return $this->userOne;
        }

        return null;
    }

    public static function pairFor(User $a, User $b): array
    {
        $ids = [$a->id, $b->id];
        sort($ids);

        return $ids;
    }
}
