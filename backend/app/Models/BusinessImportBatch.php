<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessImportBatch extends Model
{
    protected $fillable = [
        'oauth_client_id',
        'client_import_id',
        'request_hash',
        'status',
        'input_count',
        'created_count',
        'updated_count',
        'duplicate_count',
        'invalid_count',
        'conflict_count',
        'result',
    ];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
