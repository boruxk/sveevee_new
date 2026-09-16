<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageClaimRequest extends Model
{
    public const KIND_OWNERSHIP = 'ownership';

    public const KIND_CONFLICT = 'claim_conflict';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'page_id',
        'user_id',
        'conversation_id',
        'reviewed_by_user_id',
        'status',
        'message',
        'replace_existing',
        'reviewed_at',
        'kind',
        'proposed_data',
        'matched_on',
        'owner_at_request_user_id',
        'owner_at_request_claimed_at',
        'owner_at_request_is_unclaimed',
        'conflict_group_id',
    ];

    protected function casts(): array
    {
        return [
            'replace_existing' => 'boolean',
            'reviewed_at' => 'datetime',
            'proposed_data' => 'array',
            'matched_on' => 'array',
            'owner_at_request_claimed_at' => 'datetime',
            'owner_at_request_is_unclaimed' => 'boolean',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function ownerAtRequest(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_at_request_user_id');
    }
}
