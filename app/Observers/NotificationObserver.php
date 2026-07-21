<?php

namespace App\Observers;

use App\Events\NotificationChanged;
use App\Models\Notification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class NotificationObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Notification $notification): void
    {
        NotificationChanged::dispatch($notification, 'created');
    }

    public function updated(Notification $notification): void
    {
        if ($notification->wasChanged(['message', 'is_read'])) {
            NotificationChanged::dispatch($notification, 'updated');
        }
    }
}
