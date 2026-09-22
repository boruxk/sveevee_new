<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialLike extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'target_type', 'target_id', 'created_at'];
}
