<?php

namespace App\Services;

use App\Models\Notification;

class NotificationService
{
    public function getNotificationsForUser(int $userId)
    {
        return Notification::where('user_id', $userId)->latest()->get();
    }

    public function getUnreadNotificationsCount(int $userId)
    {
        return Notification::where('user_id', $userId)->where('is_read', false)->count();
    }

    public function markAsRead(Notification $notification)
    {
        $notification->update(['is_read' => true]);

        return $notification;
    }

    public function createNotification(int $userId, string $message)
    {
        return Notification::create([
            'user_id' => $userId,
            'message' => $message,
        ]);
    }
}
