<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedTerm extends Model
{
    protected $fillable = [
        'term',
        'normalized_term',
        'locale',
        'active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
