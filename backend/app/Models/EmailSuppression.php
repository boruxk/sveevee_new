<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSuppression extends Model
{
    protected $fillable = [
        'email',
        'reason',
        'diagnostic',
        'source_delivery_id',
        'suppressed_at',
    ];

    protected function casts(): array
    {
        return [
            'suppressed_at' => 'datetime',
        ];
    }

    public function sourceDelivery(): BelongsTo
    {
        return $this->belongsTo(EmailDelivery::class, 'source_delivery_id');
    }
}
