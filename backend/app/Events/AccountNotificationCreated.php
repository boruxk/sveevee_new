<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccountNotificationCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $tries = 5;

    public int $backoff = 15;

    public function __construct(
        public readonly int $recipientId,
        public readonly array $notification,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'account.notification.created';
    }

    public function broadcastWith(): array
    {
        return ['notification' => $this->notification];
    }
}
