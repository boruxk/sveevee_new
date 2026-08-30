<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiPageImport extends Model
{
    protected $fillable = [
        'created_by_user_id',
        'client_import_id',
        'status',
        'input_count',
        'created_count',
        'duplicate_count',
        'invalid_count',
        'created_page_ids',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'created_page_ids' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(AiPageImportRow::class)->orderBy('position');
    }
}
