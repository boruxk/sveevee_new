<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'given_name',
        'family_name',
        'email',
        'email_verified_at',
        'google_id',
        'password',
        'locale',
        'role',
        'banned_at',
        'banned_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'display_name',
        'role_names',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'banned_at' => 'datetime',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->orderBy('type');
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class)->latest();
    }

    public function pageRatings(): HasMany
    {
        return $this->hasMany(PageRating::class)->latest();
    }

    public function conversationsAsUserOne(): HasMany
    {
        return $this->hasMany(Conversation::class, 'user_one_id');
    }

    public function conversationsAsUserTwo(): HasMany
    {
        return $this->hasMany(Conversation::class, 'user_two_id');
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'sender_id');
    }

    public function receivedUnreadMessages()
    {
        return ChatMessage::query()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $this->id)
            ->whereHas('conversation', fn ($query) => $query->forParticipant($this));
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function getDisplayNameAttribute(): string
    {
        $fullName = trim((string) $this->given_name.' '.(string) $this->family_name);

        return $fullName !== '' ? $fullName : ($this->name ?: Str::before($this->email, '@'));
    }

    public function getRoleNamesAttribute(): array
    {
        return [$this->role ?: 'user'];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $user->email = strtolower(trim((string) $user->email));
            $user->name = $user->name ?: trim((string) $user->given_name.' '.(string) $user->family_name);
        });

        static::created(function (User $user): void {
            $user->profile()->firstOrCreate([]);
        });
    }
}
