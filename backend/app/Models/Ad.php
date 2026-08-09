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
        'category',
        'image_path',
        'image_original_name',
        'status',
        'expires_at',
        'city',
        'neighborhood',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

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
        return $query->where('status', 'active')->notExpired();
    }

    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now());
    }

    public function isVisible(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }

    public function scopeInLocation(Builder $query, ?string $city = null, ?string $neighborhood = null): Builder
    {
        return $query
            ->when($city, function (Builder $query, string $city): void {
                $this->whereResolvedLocation($query, 'city', $city);
            })
            ->when($neighborhood, function (Builder $query, string $neighborhood): void {
                $this->whereResolvedLocation($query, 'neighborhood', $neighborhood);
            });
    }

    private function whereResolvedLocation(Builder $query, string $field, string $value): void
    {
        $query->where(function (Builder $query) use ($field, $value): void {
            $query
                ->where($field, $value)
                ->orWhere(function (Builder $query) use ($field, $value): void {
                    $query
                        ->whereNull($field)
                        ->where(function (Builder $query) use ($field, $value): void {
                            $query
                                ->where(function (Builder $query) use ($field, $value): void {
                                    $query
                                        ->whereNull('page_id')
                                        ->whereHas('user.profile', fn (Builder $profile) => $profile->where($field, $value));
                                })
                                ->orWhere(function (Builder $query) use ($field, $value): void {
                                    $query
                                        ->whereNotNull('page_id')
                                        ->whereHas('page', fn (Builder $page) => $this->wherePageAddressField($page, $field, $value));
                                });
                        });
                });
        });
    }

    private function wherePageAddressField(Builder $query, string $field, string $value): void
    {
        $query->where(function (Builder $query) use ($field, $value): void {
            $query->where("setup->address->{$field}", $value);

            if ($field === 'city') {
                $query->orWhere('address', 'like', '%'.$value.'%');
            }
        });
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path ? url(Storage::url($this->image_path)) : null);
    }
}
