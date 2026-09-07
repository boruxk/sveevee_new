<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessImportPage extends Model
{
    protected $primaryKey = 'page_id';

    public $incrementing = false;

    protected $fillable = [
        'page_id',
        'created_by_oauth_client_id',
        'last_updated_by_oauth_client_id',
        'last_payload_hash',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
