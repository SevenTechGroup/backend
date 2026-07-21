<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Notification $notification,
        public string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'notification.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'notification' => [
                'id' => $this->notification->id,
                'user_id' => $this->notification->user_id,
                'message' => $this->notification->message,
                'is_read' => $this->notification->is_read,
                'created_at' => $this->notification->created_at?->toISOString(),
                'updated_at' => $this->notification->updated_at?->toISOString(),
            ],
        ];
    }
}
