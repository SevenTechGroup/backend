<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Résout ou génère l'identifiant de corrélation X-Request-ID,
 * l'attache au contexte de log et le propage sur la réponse.
 */
class RequestIdMiddleware
{
    /**
     * Longueur maximale autorisée pour un X-Request-ID entrant.
     */
    private const MAX_LENGTH = 128;

    /**
     * Motif d'un X-Request-ID valide : 1 à 128 caractères alphanumériques,
     * tiret ou trait de soulignement.
     */
    private const PATTERN = '/^[A-Za-z0-9_-]{1,'.self::MAX_LENGTH.'}$/';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        $requestId = $this->resolveRequestId($request);

        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    /**
     * Réutilise l'en-tête X-Request-ID fourni s'il est valide,
     * sinon génère un UUID v4.
     */
    private function resolveRequestId(Request $request): string
    {
        // Laravel expose la première occurrence de l'en-tête (R2.4).
        $incoming = trim((string) $request->header('X-Request-ID'));

        if ($incoming !== '' && preg_match(self::PATTERN, $incoming) === 1) {
            return $incoming;
        }

        return Str::uuid()->toString();
    }
}
