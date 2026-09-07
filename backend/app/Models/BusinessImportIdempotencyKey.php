<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessImportIdempotencyKey extends Model
{
    protected $fillable = [
        'oauth_client_id',
        'idempotency_key',
        'request_hash',
        'status',
        'response_status',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'result' => 'array',
        ];
    }
}
