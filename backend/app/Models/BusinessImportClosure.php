<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessImportClosure extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_closed' => 'date', 'removed_page_id' => 'integer', 'page_snapshot' => 'array',
            'removed_at' => 'datetime', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(BusinessImportClosureEvent::class, 'closure_id');
    }
}
