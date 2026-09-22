<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProPayment extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['checkout_url', 'metadata', 'provider_low_profile_id', 'provider_transaction_id', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'amount_minor' => 'integer',
            'terminal_number' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(BusinessProSubscription::class, 'subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
