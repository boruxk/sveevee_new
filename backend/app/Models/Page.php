<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Page extends Model
{
    public const TYPE_BUSINESS = 'business';
    public const TYPE_COMMUNITY = 'community';

    protected $fillable = [
        'user_id',
        'type',
        'name',
        'public_description',
        'contact_email',
        'phone',
        'address',
        'palette_key',
        'logo_path',
        'banner_path',
        'setup',
    ];

    protected function casts(): array
    {
        return [
            'setup' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class)->latest();
    }

    public function products(): HasMany
    {
        return $this->hasMany(PageProduct::class)->latest();
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(PageRating::class)->latest();
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo_path ? url(Storage::url($this->logo_path)) : null);
    }

    protected function bannerUrl(): Attribute
    {
        return Attribute::get(fn () => $this->banner_path ? url(Storage::url($this->banner_path)) : null);
    }
}
