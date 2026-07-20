<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Empreinte du corps pour détecter les corps divergents (R4.5).
        $fingerprint = hash('sha256', $request->getContent());

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
}
