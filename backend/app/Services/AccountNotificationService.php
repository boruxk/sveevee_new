<?php

namespace App\Services;

use App\Events\AccountNotificationCreated;
use App\Jobs\SendAccountNotificationEmail;
use App\Models\Page;
use App\Models\User;
use App\Support\AccountNotificationType;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AccountNotificationService
{
    public function create(User $recipient, string $type, array $data): DatabaseNotification
    {
        if (! in_array($type, AccountNotificationType::ALL, true)) {
            throw new InvalidArgumentException("Unsupported account notification type [{$type}].");
        }

        $notification = DatabaseNotification::query()->create([
            'id' => (string) Str::orderedUuid(),
            'type' => $type,
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->getKey(),
            'data' => $data,
            'read_at' => null,
        ]);

        $payload = $this->payload($notification);
        AccountNotificationCreated::dispatch($recipient->id, $payload);

        if (in_array($type, AccountNotificationType::EMAIL_TYPES, true)) {
            SendAccountNotificationEmail::dispatch($notification->id)->afterCommit();
        }

        return $notification;
    }

    public function createForAdmins(string $type, array $data): Collection
    {
        return User::query()
            ->where('role', 'admin')
            ->whereNull('banned_at')
            ->get()
            ->map(fn (User $admin): DatabaseNotification => $this->create($admin, $type, $data));
    }

    public function pageSnapshot(Page $page): array
    {
        return [
            'id' => $page->id,
            'name' => $page->name,
            'type' => $page->type,
            'public_path' => $page->public_path,
        ];
    }

    public function summary(User $user): array
    {
        $query = $user->notifications();

        return [
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
            'latest_id' => (clone $query)
                ->latest('created_at')
                ->latest('id')
                ->value('id'),
        ];
    }

    public function payload(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }
}
