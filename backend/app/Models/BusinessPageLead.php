<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessPageLead extends Model
{
    protected $fillable = [
        'page_id',
        'status',
        'business_name',
        'city',
        'category_key',
        'full_name',
        'email',
        'phone',
        'locale',
        'created_page',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'fbclid',
        'landing_url',
        'ip_hash',
        'user_agent',
        'consented_at',
    ];

    protected function casts(): array
    {
        return [
            'created_page' => 'boolean',
            'consented_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
