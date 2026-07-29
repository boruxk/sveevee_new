<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Ad extends Model
{
    public const TYPE_PRIVATE = 'private_ad';
    public const TYPE_BUSINESS = 'business_ad';
    public const TYPE_COMMUNITY = 'community_ad';

    protected $fillable = [
        'user_id',
        'page_id',
        'type',
        'title',
        'text',
        'image_path',
        'status',
        'city',
        'neighborhood',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeInLocation(Builder $query, ?string $city = null, ?string $neighborhood = null): Builder
    {
        return $query
            ->when($city, function (Builder $query, string $city): void {
                $query->where(function (Builder $query) use ($city): void {
                    $query
                        ->where('city', $city)
                        ->orWhere(function (Builder $query) use ($city): void {
                            $query
                                ->whereNull('city')
                                ->whereHas('user.profile', fn (Builder $profile) => $profile->where('city', $city));
                        });
                });
            })
            ->when($neighborhood, function (Builder $query, string $neighborhood): void {
                $query->where(function (Builder $query) use ($neighborhood): void {
                    $query
                        ->where('neighborhood', $neighborhood)
                        ->orWhere(function (Builder $query) use ($neighborhood): void {
                            $query
                                ->whereNull('neighborhood')
                                ->whereHas('user.profile', fn (Builder $profile) => $profile->where('neighborhood', $neighborhood));
                        });
                });
            });
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path ? url(Storage::url($this->image_path)) : null);
    }
}
