<?php

namespace Tests\Feature;

use App\Http\Middleware\RequestIdMiddleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequestIdMiddlewareTest extends TestCase
{
    private const HEADER = 'X-Request-ID';

    private const ROUTE = '/_test/request-id';

    protected function setUp(): void
    {
        parent::setUp();

        // Le middleware n'est pas encore enregistré globalement (tâche 6) :
        // on définit une route ad-hoc enveloppée par le middleware sous test.
        Route::middleware(RequestIdMiddleware::class)
            ->get(self::ROUTE, fn () => response()->json(['ok' => true]));
    }

    /**
     * Motif d'un UUID version 4 (RFC 4122) : le 13e caractère hexadécimal est
     * `4` et le 17e appartient à {8, 9, a, b}.
     */
    private function assertIsUuidV4(?string $value): void
    {
        $this->assertNotNull($value);
        $this->assertTrue(Str::isUuid($value), "La valeur [{$value}] n'est pas un UUID valide.");
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
            "La valeur [{$value}] n'est pas un UUID version 4.",
        );
    }

    public function test_valid_request_id_is_reused_in_response(): void
    {
        $requestId = 'abc-123_XYZ';

        $response = $this->withHeader(self::HEADER, $requestId)->getJson(self::ROUTE);

        $response->assertOk();
        $this->assertSame($requestId, $response->headers->get(self::HEADER));
    }

    public function test_missing_header_generates_uuid_v4(): void
    {
        $response = $this->getJson(self::ROUTE);

        $response->assertOk();
        $this->assertIsUuidV4($response->headers->get(self::HEADER));
    }

    public function test_whitespace_header_generates_uuid_v4(): void
    {
        $response = $this->withHeader(self::HEADER, '   ')->getJson(self::ROUTE);

        $response->assertOk();
        $this->assertIsUuidV4($response->headers->get(self::HEADER));
    }

    public function test_header_longer_than_128_chars_generates_uuid_v4(): void
    {
        $tooLong = str_repeat('a', 129);

        $response = $this->withHeader(self::HEADER, $tooLong)->getJson(self::ROUTE);

        $response->assertOk();
        $returned = $response->headers->get(self::HEADER);
        $this->assertNotSame($tooLong, $returned);
        $this->assertIsUuidV4($returned);
    }

    public function test_header_with_invalid_chars_generates_uuid_v4(): void
    {
        foreach (['has spaces', 'inva@lid', 'slash/here', 'e#mail'] as $invalid) {
            $response = $this->withHeader(self::HEADER, $invalid)->getJson(self::ROUTE);

            $response->assertOk();
            $returned = $response->headers->get(self::HEADER);
            $this->assertNotSame($invalid, $returned);
            $this->assertIsUuidV4($returned);
        }
    }

    public function test_response_always_contains_request_id_header(): void
    {
        $withHeader = $this->withHeader(self::HEADER, 'provided_id')->getJson(self::ROUTE);
        $this->assertTrue($withHeader->headers->has(self::HEADER));

        $withoutHeader = $this->getJson(self::ROUTE);
        $this->assertTrue($withoutHeader->headers->has(self::HEADER));
    }

    public function test_log_context_is_enriched_with_resolved_id(): void
    {
        Log::spy();

        $requestId = 'trace_ABC-123';

        $this->withHeader(self::HEADER, $requestId)->getJson(self::ROUTE)->assertOk();

        Log::shouldHaveReceived('withContext')
            ->once()
            ->with(['request_id' => $requestId]);
    }

    public function test_log_context_is_enriched_with_generated_id_when_header_absent(): void
    {
        Log::spy();

        $response = $this->getJson(self::ROUTE);
        $response->assertOk();

        $generatedId = $response->headers->get(self::HEADER);
        $this->assertIsUuidV4($generatedId);

        Log::shouldHaveReceived('withContext')
            ->once()
            ->with(['request_id' => $generatedId]);
    }
}
