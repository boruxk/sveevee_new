<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessImportSourceAlias extends Model
{
    public $timestamps = false;

    protected $fillable = ['provider', 'source_id', 'business_import_source_id'];

    public function association(): BelongsTo
    {
        return $this->belongsTo(BusinessImportSource::class, 'business_import_source_id');
    }
}
