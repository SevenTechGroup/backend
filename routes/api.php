<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RealtimeConfigController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TerritoryController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['auth:api', 'throttle:60,1']]);

require __DIR__.'/channels.php';

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
});

Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware(['auth:api', 'throttle:60,1']);
Route::get('/auth/me', [AuthController::class, 'me'])->middleware(['auth:api', 'throttle:60,1']);

Route::middleware('throttle:60,1')->group(function () {
    Route::get('/territories', [TerritoryController::class, 'index']);
    Route::get('/categories', [CategoryController::class, 'index']);
});

Route::middleware(['auth:api', 'throttle:60,1'])->group(function () {
    Route::get('/attachments/{attachment}/content', [AttachmentController::class, 'show']);
    Route::get('/reports', [ReportController::class, 'index']);
    Route::post('/reports', [ReportController::class, 'store'])->middleware('idempotency');
    Route::get('/reports/{report}', [ReportController::class, 'show']);
    Route::put('/reports/{report}', [ReportController::class, 'update']);

    Route::get('/assignments', [AssignmentController::class, 'index']);
    Route::post('/assignments', [AssignmentController::class, 'store']);
    Route::put('/assignments/{assignment}', [AssignmentController::class, 'update']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::get('/realtime/config', RealtimeConfigController::class);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/agents', [UserController::class, 'agents']);
});
