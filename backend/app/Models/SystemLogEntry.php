<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemLogEntry extends Model
{
    public const SOURCE_AUTOMATION_WORKER = 'automation_worker';

    public const TYPE_BUSINESS_IMPORT_RUN = 'business_import_run';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_WARNING = 'warning';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'source',
        'type',
        'status',
        'external_id',
        'actor_type',
        'actor_identifier',
        'payload_hash',
        'data',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
