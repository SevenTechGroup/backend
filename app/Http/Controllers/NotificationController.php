<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notificationService) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Notification::class);

        return response()->json(['data' => $this->notificationService->getNotificationsForUser(auth('api')->id())]);
    }

    public function markAsRead(Notification $notification): JsonResponse
    {
        $this->authorize('update', $notification);
        $this->notificationService->markAsRead($notification);

        return response()->json(['message' => 'Notification marked as read']);
    }
}
