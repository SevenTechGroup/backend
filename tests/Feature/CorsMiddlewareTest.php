<?php

namespace Tests\Feature;

use App\Http\Middleware\CorsMiddleware;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CorsMiddlewareTest extends TestCase
{
    private const ROUTE = '/_test/cors';

    private const ALLOWED_ORIGIN = 'https://app.example.tld';

    private const HEADER_ALLOW_ORIGIN = 'Access-Control-Allow-Origin';

    private const HEADER_ALLOW_METHODS = 'Access-Control-Allow-Methods';

    private const HEADER_ALLOW_HEADERS = 'Access-Control-Allow-Headers';

    private const HEADER_ALLOW_CREDENTIALS = 'Access-Control-Allow-Credentials';

    protected function setUp(): void
    {
        parent::setUp();

        // Le middleware n'est pas encore enregistré globalement (tâche 6) :
        // on définit une route ad-hoc enveloppée par le middleware sous test.
        // La configuration `cors.*` est lue au moment de la requête, ce qui
        // permet de surcharger `config()` dans chaque test avant l'appel.
        Route::middleware(CorsMiddleware::class)
            ->match(['GET', 'POST', 'OPTIONS'], self::ROUTE, fn () => response()->json(['ok' => true]));
    }

    /**
     * Requirement 1.3 : une origine autorisée (correspondance exacte) reçoit
     * l'en-tête Access-Control-Allow-Origin contenant exactement cette origine.
     */
    public function test_allowed_origin_receives_allow_origin_header(): void
    {
        config()->set('cors.allowed_origins', [self::ALLOWED_ORIGIN]);

        $response = $this->withHeader('Origin', self::ALLOWED_ORIGIN)->getJson(self::ROUTE);

        $response->assertOk();
        $this->assertSame(self::ALLOWED_ORIGIN, $response->headers->get(self::HEADER_ALLOW_ORIGIN));
    }

    /**
     * Requirement 1.4 : une origine absente de la liste blanche ne reçoit pas
     * l'en-tête Allow-Origin, mais la requête aboutit tout de même (200).
     */
    public function test_non_allowed_origin_has_no_allow_origin_header_but_succeeds(): void
    {
        config()->set('cors.allowed_origins', [self::ALLOWED_ORIGIN]);

        $response = $this->withHeader('Origin', 'https://evil.example.tld')->getJson(self::ROUTE);

        $response->assertOk();
        $this->assertFalse($response->headers->has(self::HEADER_ALLOW_ORIGIN));
    }

    /**
     * Requirement 1.2 : liste blanche vide → aucune origine autorisée, même
     * pour une origine plausible (sécurité par défaut, jamais de joker).
     */
    public function test_empty_config_omits_allow_origin_header(): void
    {
        config()->set('cors.allowed_origins', []);

        $response = $this->withHeader('Origin', self::ALLOWED_ORIGIN)->getJson(self::ROUTE);

        $response->assertOk();
        $this->assertFalse($response->headers->has(self::HEADER_ALLOW_ORIGIN));
    }

    /**
     * Requirement 1.5 : préflight OPTIONS depuis une origine autorisée →
     * statut 204, Allow-Methods énumérant les méthodes, Allow-Headers présent.
     */
    public function test_preflight_from_allowed_origin_returns_204_with_headers(): void
    {
        config()->set('cors.allowed_origins', [self::ALLOWED_ORIGIN]);

        $response = $this->withHeader('Origin', self::ALLOWED_ORIGIN)
            ->json('OPTIONS', self::ROUTE);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(self::ALLOWED_ORIGIN, $response->headers->get(self::HEADER_ALLOW_ORIGIN));

        $methods = $response->headers->get(self::HEADER_ALLOW_METHODS);
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->assertStringContainsString($method, $methods);
        }

        $this->assertTrue($response->headers->has(self::HEADER_ALLOW_HEADERS));
        $this->assertNotEmpty($response->headers->get(self::HEADER_ALLOW_HEADERS));
    }

    /**
     * Requirement 1.6 : préflight OPTIONS depuis une origine non autorisée →
     * statut 204 sans aucun en-tête Allow-Origin/Methods/Headers.
     */
    public function test_preflight_from_non_allowed_origin_returns_204_without_cors_headers(): void
    {
        config()->set('cors.allowed_origins', [self::ALLOWED_ORIGIN]);

        $response = $this->withHeader('Origin', 'https://evil.example.tld')
            ->json('OPTIONS', self::ROUTE);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertFalse($response->headers->has(self::HEADER_ALLOW_ORIGIN));
        $this->assertFalse($response->headers->has(self::HEADER_ALLOW_METHODS));
        $this->assertFalse($response->headers->has(self::HEADER_ALLOW_HEADERS));
    }

    /**
     * Requirement 1.7 : l'en-tête Allow-Headers d'un préflight autorisé inclut
     * X-Request-ID et X-Idempotency-Key.
     */
    public function test_allow_headers_includes_correlation_and_idempotency_headers(): void
    {
        config()->set('cors.allowed_origins', [self::ALLOWED_ORIGIN]);

        $response = $this->withHeader('Origin', self::ALLOWED_ORIGIN)
            ->json('OPTIONS', self::ROUTE);

        $headers = $response->headers->get(self::HEADER_ALLOW_HEADERS);
        $this->assertStringContainsString('X-Request-ID', $headers);
        $this->assertStringContainsString('X-Idempotency-Key', $headers);
    }

    /**
     * Requirement 1.3 : la correspondance est sensible à la casse. Une origine
     * ne différant que par la casse d'une entrée autorisée n'est pas autorisée.
     */
    public function test_origin_matching_is_case_sensitive(): void
    {
        config()->set('cors.allowed_origins', [self::ALLOWED_ORIGIN]);

        $response = $this->withHeader('Origin', 'https://APP.example.tld')->getJson(self::ROUTE);

        $response->assertOk();
        $this->assertFalse($response->headers->has(self::HEADER_ALLOW_ORIGIN));
    }

    /**
     * Requirement 1.8 : Access-Control-Allow-Credentials vaut `true` pour une
     * origine autorisée lorsque supports_credentials est activé, et est absent
     * lorsqu'il est désactivé.
     */
    public function test_allow_credentials_reflects_supports_credentials_config(): void
    {
        config()->set('cors.allowed_origins', [self::ALLOWED_ORIGIN]);
        config()->set('cors.supports_credentials', true);

        $withCredentials = $this->withHeader('Origin', self::ALLOWED_ORIGIN)->getJson(self::ROUTE);
        $withCredentials->assertOk();
        $this->assertSame('true', $withCredentials->headers->get(self::HEADER_ALLOW_CREDENTIALS));

        config()->set('cors.supports_credentials', false);

        $withoutCredentials = $this->withHeader('Origin', self::ALLOWED_ORIGIN)->getJson(self::ROUTE);
        $withoutCredentials->assertOk();
        $this->assertFalse($withoutCredentials->headers->has(self::HEADER_ALLOW_CREDENTIALS));
    }
}
