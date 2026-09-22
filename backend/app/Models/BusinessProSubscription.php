<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessProSubscription extends Model
{
    // Billing writes explicitly use forceFill; HTTP request data is never mass assignable.
    protected $guarded = ['*'];

    protected $hidden = ['provider_token', 'provider_customer_id'];

    protected function casts(): array
    {
        return [
            'provider_token' => 'encrypted',
            'amount_minor' => 'integer',
            'terminal_number' => 'integer',
            'included_pages' => 'integer',
            'token_expires_month' => 'integer',
            'token_expires_year' => 'integer',
            'cancel_at_period_end' => 'boolean',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_charge_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BusinessProPayment::class, 'subscription_id');
    }
}
