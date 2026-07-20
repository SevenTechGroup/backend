<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CorsMiddleware
 *
 * Applique une liste blanche d'origines (Cross-Origin Resource Sharing) de
 * manière autonome, sans dépendre du middleware natif HandleCors de Laravel.
 *
 * Le composant ne lit QUE les clés applicatives suivantes :
 *   - cors.allowed_origins      (liste blanche, potentiellement vide → fail-safe)
 *   - cors.allowed_methods      (méthodes exposées en préflight)
 *   - cors.allowed_headers      (en-têtes exposés en préflight)
 *   - cors.supports_credentials (autorise les cookies cross-origin)
 *
 * Sécurité : le joker « * » n'est jamais émis pour Access-Control-Allow-Origin.
 * Seules des origines explicites, correspondant exactement (sensible à la casse)
 * à une entrée de la liste blanche, sont renvoyées.
 *
 * Validates: Requirements 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8
 */
class CorsMiddleware
{
    /**
     * Longueur maximale valide d'une origine configurée (schéma + hôte + port).
     */
    private const MAX_ORIGIN_LENGTH = 253;

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $originAllowed = $origin !== null && $this->isOriginAllowed($origin);

        // Préflight : court-circuite la pile et répond immédiatement en 204.
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response('', 204);

            if ($originAllowed) {
                $this->applyPreflightHeaders($response, $origin);
            }

            return $response;
        }

        // Requête normale : traiter d'abord, puis décorer la réponse.
        $response = $next($request);

        if ($originAllowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);

            if ($this->supportsCredentials()) {
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
            }
        }

        return $response;
    }

    /**
     * Détermine si l'origine correspond exactement (sensible à la casse) à une
     * entrée valide de la liste blanche. Les origines configurées dont la
     * longueur dépasse MAX_ORIGIN_LENGTH sont ignorées (traitées comme absentes).
     */
    private function isOriginAllowed(string $origin): bool
    {
        foreach ($this->allowedOrigins() as $allowed) {
            if (strlen($allowed) > self::MAX_ORIGIN_LENGTH) {
                continue;
            }

            if ($allowed === $origin) {
                return true;
            }
        }

        return false;
    }

    /**
     * Positionne les en-têtes CORS d'un préflight autorisé.
     */
    private function applyPreflightHeaders(Response $response, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods()));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders()));

        if ($this->supportsCredentials()) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }
    }

    /**
     * @return array<int, string>
     */
    private function allowedOrigins(): array
    {
        return array_values(array_filter(
            (array) config('cors.allowed_origins', []),
            static fn ($value): bool => is_string($value) && $value !== ''
        ));
    }

    /**
     * @return array<int, string>
     */
    private function allowedMethods(): array
    {
        return (array) config('cors.allowed_methods', []);
    }

    /**
     * @return array<int, string>
     */
    private function allowedHeaders(): array
    {
        return (array) config('cors.allowed_headers', []);
    }

    private function supportsCredentials(): bool
    {
        return (bool) config('cors.supports_credentials', false);
    }
}
