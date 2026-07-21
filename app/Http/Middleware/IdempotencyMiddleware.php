<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garantit qu'une clé X-Idempotency-Key ne produit qu'un seul Report
 * et rejoue la réponse enregistrée en cas de nouvelle tentative identique.
 *
 * S'applique uniquement à POST /api/reports.
 */
class IdempotencyMiddleware
{
    /**
     * Longueur maximale autorisée pour une clé d'idempotence.
     */
    private const MAX_KEY_LENGTH = 128;

    /**
     * Statut d'un traitement en cours.
     */
    private const STATUS_PROCESSING = 'processing';

    /**
     * Statut d'un traitement terminé avec succès.
     */
    private const STATUS_COMPLETED = 'completed';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('X-Idempotency-Key'));

        // Clé absente, vide ou trop longue → 422 sans création de Report (R3.3, R4.6).
        if ($key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
            return response()->json(
                ['message' => 'Clé d\'idempotence absente ou invalide.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Empreinte canonique pour rester stable entre deux encodages multipart
        // équivalents (la boundary HTTP change à chaque nouvelle tentative).
        $fingerprint = $this->fingerprint($request);

        return DB::transaction(function () use ($request, $next, $key, $fingerprint) {
            $record = IdempotencyKey::where('key', $key)
                ->lockForUpdate() // verrou pessimiste — sérialise les requêtes concurrentes (R4.4)
                ->first();

            if ($record !== null) {
                if ($record->isExpired()) {
                    // Clé expirée → supprimée puis traitée comme nouvelle (R3.5, R4.8).
                    $record->delete();
                } elseif ($record->status === self::STATUS_PROCESSING) {
                    // Traitement concurrent déjà en cours (R3.7, R4.7).
                    abort(Response::HTTP_CONFLICT, 'Un traitement pour cette clé est déjà en cours.');
                } elseif ($record->request_fingerprint !== $fingerprint) {
                    // Même clé, corps différent → conflit (R4.5).
                    abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Conflit de clé d\'idempotence.');
                } else {
                    // Clé terminée + empreinte identique → rejeu de la réponse (R4.1, R4.2).
                    return $this->replay($record);
                }
            }

            // Réservation de la clé avant exécution du controller (R3.2).
            $record = IdempotencyKey::create([
                'key' => $key,
                'request_fingerprint' => $fingerprint,
                'status' => self::STATUS_PROCESSING,
            ]);

            $response = $next($request);

            if ($response->getStatusCode() < Response::HTTP_BAD_REQUEST) {
                // Succès → persistance du résultat pour un futur rejeu (R3.2, R3.4).
                $this->persistResponse($record, $response);
            } else {
                // Échec → suppression pour autoriser une nouvelle tentative légitime.
                $record->delete();
            }

            return $response;
        });
    }

    /**
     * Reconstruit une réponse à partir de l'enregistrement stocké.
     */
    private function replay(IdempotencyKey $record): Response
    {
        return response($record->response_body, $record->response_status)
            ->header('Content-Type', 'application/json');
    }

    /**
     * Enregistre le résultat d'un traitement réussi sur la clé.
     */
    private function persistResponse(IdempotencyKey $record, Response $response): void
    {
        $body = $response->getContent();

        $record->update([
            'status' => self::STATUS_COMPLETED,
            'response_status' => $response->getStatusCode(),
            'response_body' => $body,
            'report_id' => data_get(json_decode($body, true), 'data.id'),
        ]);
    }

    /**
     * Produit une empreinte sémantique des champs et fichiers reçus.
     *
     * @throws JsonException
     */
    private function fingerprint(Request $request): string
    {
        $input = $request->isJson()
            ? $request->json()->all()
            : $request->request->all();

        $payload = $this->canonicalize([
            'input' => $input,
            'files' => $this->fingerprintFiles($request->allFiles()),
        ]);

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /**
     * @param  array<string, UploadedFile|array>  $files
     * @return array<string, mixed>
     */
    private function fingerprintFiles(array $files): array
    {
        $fingerprints = [];

        foreach ($files as $field => $file) {
            $fingerprints[$field] = is_array($file)
                ? $this->fingerprintFiles($file)
                : $this->fingerprintFile($file);
        }

        return $fingerprints;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function fingerprintFile(UploadedFile $file): array
    {
        $contentHash = null;
        $path = $file->getPathname();

        if ($file->getError() === UPLOAD_ERR_OK && is_file($path)) {
            $contentHash = hash_file('sha256', $path);
            if ($contentHash === false) {
                throw new RuntimeException('Impossible de calculer l\'empreinte du fichier envoyé.');
            }
        }

        return [
            'client_mime_type' => $file->getClientMimeType(),
            'error' => $file->getError(),
            'name' => $file->getClientOriginalName(),
            'sha256' => $contentHash,
            'size' => $file->getSize(),
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
