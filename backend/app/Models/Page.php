<?php

namespace App\Models;

use App\Support\PublicSlug;
use Illuminate\Database\Eloquent\Builder;
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
        'created_by_user_id',
        'type',
        'is_unclaimed',
        'name',
        'public_description',
        'contact_email',
        'phone',
        'address',
        'category_key',
        'palette_key',
        'logo_path',
        'logo_original_name',
        'banner_path',
        'banner_original_name',
        'setup',
        'source_url',
        'source_checked_at',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'setup' => 'array',
            'is_unclaimed' => 'boolean',
            'source_checked_at' => 'date',
            'claimed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function claimRequests(): HasMany
    {
        return $this->hasMany(PageClaimRequest::class)->latest();
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class)->latest();
    }

    public function products(): HasMany
    {
        return $this->hasMany(PageProduct::class)->latest();
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PagePrice::class)->latest();
    }

    public function services(): HasMany
    {
        return $this->hasMany(PageService::class)->latest();
    }

    public function events(): HasMany
    {
        return $this->hasMany(PageEvent::class)->orderBy('event_date')->orderBy('event_time');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(PageRating::class)->latest();
    }

    public function chatConversations(): HasMany
    {
        return $this->hasMany(PageConversation::class)->latest('last_message_at');
    }

    public function scopeManaged(Builder $query): Builder
    {
        return $query->where('is_unclaimed', false);
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo_path ? url(Storage::url($this->logo_path)) : null);
    }

    protected function bannerUrl(): Attribute
    {
        return Attribute::get(fn () => $this->banner_path ? url(Storage::url($this->banner_path)) : null);
    }

    protected function publicSlug(): Attribute
    {
        return Attribute::get(fn (): string => PublicSlug::make([$this->name], 'page', $this->id));
    }

    protected function publicPath(): Attribute
    {
        return Attribute::get(function (): string {
            $segment = $this->type === self::TYPE_COMMUNITY ? 'community' : 'business';

            return "/{$segment}/{$this->public_slug}";
        });
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $candidate = (string) $value;

        $id = PublicSlug::idFromSlug($candidate);

        return $id ? $this->whereKey($id)->first() : null;
    }
}
