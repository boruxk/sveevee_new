<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessImportClosureEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'result' => 'array', 'release' => 'date', 'created_at' => 'datetime'];
    }
}
