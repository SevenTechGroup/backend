<?php

namespace App\Services;

use App\Contracts\AssetStorage;
use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReportService
{
    public function __construct(private AssetStorage $assetStorage) {}

    public function getVisibleReports(User $user): Collection
    {
        $query = Report::query();

        if ($user->hasRole(UserRole::Agent)) {
            $query->whereHas(
                'assignments',
                fn ($assignmentQuery) => $assignmentQuery->where('user_id', $user->id),
            );
        } elseif (! $user->hasRole(UserRole::Manager)) {
            $query->where('user_id', $user->id);
        }

        return $query
            ->with(['category', 'territory', 'user', 'attachments', 'location'])
            ->latest()
            ->get();
    }

    public function createReport(array $data, int $userId): Report
    {
        $photo = $data['photo'] ?? null;
        $coordinates = $data['coordinates'] ?? null;
        $uploadedAsset = null;

        unset($data['photo'], $data['coordinates'], $data['location_consent_accepted']);

        if ($photo instanceof UploadedFile) {
            $folder = trim((string) config('services.cloudinary.folder', 'sahel-signal'), '/');
            $uploadedAsset = $this->assetStorage->upload($photo, "{$folder}/reports");
        }

        try {
            return DB::transaction(function () use (
                $data,
                $userId,
                $coordinates,
                $uploadedAsset,
            ): Report {
                $report = Report::create([
                    ...$data,
                    'user_id' => $userId,
                    'status' => 'received',
                ]);

                if (is_array($coordinates)) {
                    $report->location()->create([
                        'latitude' => $coordinates['latitude'],
                        'longitude' => $coordinates['longitude'],
                        'accuracy_m' => $coordinates['accuracy'],
                        'source' => 'gps',
                    ]);
                    $report->consentRecords()->create([
                        'user_id' => $userId,
                        'consent_type' => 'precise_location',
                        'granted_at' => now(),
                    ]);
                }

                if ($uploadedAsset) {
                    $report->attachments()->create($uploadedAsset);
                }

                Notification::create([
                    'user_id' => $userId,
                    'message' => 'Nouveau signalement créé : '.$report->title,
                ]);

                return $report->load(['category', 'territory', 'user', 'attachments', 'location']);
            });
        } catch (Throwable $exception) {
            if ($uploadedAsset) {
                try {
                    $this->assetStorage->delete(
                        $uploadedAsset['provider_public_id'],
                        $uploadedAsset['resource_type'],
                        $uploadedAsset['delivery_type'],
                    );
                } catch (Throwable $cleanupException) {
                    Log::warning('Cloudinary asset cleanup failed after report rollback.', [
                        'public_id' => $uploadedAsset['provider_public_id'],
                        'exception' => $cleanupException::class,
                    ]);
                }
            }

            throw $exception;
        }
    }

    public function getReport(Report $report): Report
    {
        return $report->load(['category', 'territory', 'user', 'attachments', 'location']);
    }

    public function updateReport(Report $report, array $data): Report
    {
        $report->update($data);

        return $report->fresh(['category', 'territory', 'user', 'attachments', 'location']);
    }

    public function getReportsByUser(int $userId): Collection
    {
        return Report::where('user_id', $userId)
            ->with(['category', 'territory', 'attachments', 'location'])
            ->latest()
            ->get();
    }
}
