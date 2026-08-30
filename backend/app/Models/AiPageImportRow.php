<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPageImportRow extends Model
{
    protected $fillable = ['ai_page_import_id', 'position', 'row_key', 'payload', 'page_id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(AiPageImport::class, 'ai_page_import_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
