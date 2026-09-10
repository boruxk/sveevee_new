<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessImportMatchReview extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'provider', 'source_id', 'reason', 'status', 'payload', 'candidates', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'candidates' => 'array', 'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }
}
