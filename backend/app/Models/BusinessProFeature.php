<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessProFeature extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['labels' => 'array', 'descriptions' => 'array', 'enabled' => 'boolean', 'sort_order' => 'integer'];
    }
}
