<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Client;

class BusinessImportClient extends Model
{
    public const SCOPE_READ = 'business:read';

    public const SCOPE_WRITE = 'business:write';

    protected $primaryKey = 'oauth_client_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'oauth_client_id',
        'name',
        'allowed_scopes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'allowed_scopes' => 'array',
            'active' => 'boolean',
        ];
    }

    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'oauth_client_id');
    }
}
