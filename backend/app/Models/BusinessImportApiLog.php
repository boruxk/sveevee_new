<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessImportApiLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'oauth_client_id',
        'oauth_access_token_id',
        'method',
        'route_name',
        'path',
        'status_code',
        'ip_address',
        'user_agent',
        'duration_ms',
        'payload_bytes',
        'item_count',
        'payload_hash',
    ];
}
