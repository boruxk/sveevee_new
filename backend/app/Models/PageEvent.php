<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PageEvent extends Model
{
    protected $fillable = [
        'page_id',
        'user_id',
        'name',
        'description',
        'category_key',
        'image_path',
        'image_original_name',
        'event_date',
        'event_time',
        'event_end_time',
        'address',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date:Y-m-d',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where(function (Builder $events): void {
            $events
                ->where(function (Builder $personal): void {
                    $personal
                        ->whereNull('page_id')
                        ->whereNotNull('user_id')
                        ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'));
                })
                ->orWhere(function (Builder $pageEvent): void {
                    $pageEvent
                        ->whereNotNull('page_id')
                        ->whereHas('page', fn (Builder $page) => $page
                            ->managed()
                            ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at')));
                });
        });
    }

    public function scopeInOwnerLocation(Builder $query, ?string $city, ?string $neighborhood): Builder
    {
        if (! $city && ! $neighborhood) {
            return $query;
        }

        return $query->where(function (Builder $events) use ($city, $neighborhood): void {
            $events
                ->where(function (Builder $personal) use ($city, $neighborhood): void {
                    $personal
                        ->whereNull('page_id')
                        ->whereHas('user.profile', function (Builder $profile) use ($city, $neighborhood): void {
                            $profile
                                ->when($city, fn (Builder $profile, string $city) => $profile->where('city', $city))
                                ->when($neighborhood, fn (Builder $profile, string $neighborhood) => $profile->where('neighborhood', $neighborhood));
                        });
                })
                ->orWhere(function (Builder $pageEvent) use ($city, $neighborhood): void {
                    $pageEvent
                        ->whereNotNull('page_id')
                        ->whereHas('page', function (Builder $page) use ($city, $neighborhood): void {
                            $page
                                ->when($city, function (Builder $page, string $city): void {
                                    $page->where(function (Builder $address) use ($city): void {
                                        $address
                                            ->where('setup->address->city', $city)
                                            ->orWhere('address', 'like', '%'.$city.'%');
                                    });
                                })
                                ->when($neighborhood, fn (Builder $page, string $neighborhood) => $page->where('setup->address->neighborhood', $neighborhood));
                        });
                });
        });
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path ? url(Storage::url($this->image_path)) : null);
    }
}
