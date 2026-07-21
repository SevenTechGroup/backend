<?php

namespace App\Http\Controllers;

use App\Contracts\AssetStorage;
use App\Models\Attachment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

class AttachmentController extends Controller
{
    public function show(Attachment $attachment, AssetStorage $assetStorage): Response
    {
        $attachment->loadMissing('report');
        $this->authorize('view', $attachment->report);

        try {
            $assetResponse = Http::accept($attachment->mime_type)
                ->timeout(15)
                ->get($assetStorage->signedUrl(
                    $attachment->provider_public_id,
                    $attachment->resource_type,
                    $attachment->delivery_type,
                    $attachment->format,
                ));
        } catch (Throwable $exception) {
            report($exception);
            abort(502, 'La photo du signalement est momentanément indisponible.');
        }

        if (! $assetResponse->successful()) {
            report("Cloudinary asset download failed with status {$assetResponse->status()}.");
            abort(502, 'La photo du signalement est momentanément indisponible.');
        }

        $filename = $attachment->original_filename ?: "preuve-{$attachment->id}.bin";
        $fallback = "preuve-{$attachment->id}.".($attachment->format ?: 'bin');

        return response($assetResponse->body(), 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $filename,
                $fallback,
            ),
            'Cache-Control' => 'private, max-age=900, no-transform',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
