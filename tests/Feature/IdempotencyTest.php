<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Models\Category;
use App\Models\IdempotencyKey;
use App\Models\Territory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Tests d'intégration du middleware d'idempotence sur la création de Report.
 *
 * Le middleware n'étant pas encore enregistré globalement sur /api/reports
 * (Task 6), on expose une route ad-hoc authentifiée qui applique le middleware
 * puis exécute le vrai ReportController::store (donc le vrai ReportService).
 *
 * Valide : Requirements 3.2, 3.3, 3.6, 3.7, 4.1, 4.2, 4.5, 4.6, 4.7, 4.8
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/_test/reports';

    private string $token;

    private int $categoryId;

    private int $territoryId;

    protected function setUp(): void
    {
        parent::setUp();

        // Données de domaine nécessaires à la création d'un Report réel.
        $territory = Territory::create([
            'name' => 'Dakar',
            'code' => 'DKR',
            'is_active' => true,
        ]);
        $category = Category::create([
            'name' => 'Déchets',
            'slug' => 'dechets',
            'severity' => 'medium',
            'description' => 'Déchets',
            'is_active' => true,
        ]);

        $this->territoryId = $territory->id;
        $this->categoryId = $category->id;

        // Utilisateur authentifié via JWT (flux réel register + login).
        $this->postJson('/api/auth/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
        ])->assertCreated();

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'password123',
        ])->assertOk()->json('token');

        // Route ad-hoc appliquant le middleware sous test + création réelle.
        Route::middleware(['auth:api', IdempotencyMiddleware::class])
            ->post(self::ROUTE, [ReportController::class, 'store']);
    }

    /**
     * Charge utile valide pour créer un Report.
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Déchet sauvage',
            'description' => 'Un dépôt sauvage est visible devant la mairie du quartier.',
            'category_id' => $this->categoryId,
            'territory_id' => $this->territoryId,
            'location_text' => 'Quartier Centre',
            'priority' => 'medium',
        ], $overrides);
    }

    /**
     * Envoie une requête de création avec en-têtes personnalisés.
     */
    private function postReport(array $headers, array $payload)
    {
        return $this->withHeaders(array_merge([
            'Authorization' => 'Bearer '.$this->token,
        ], $headers))->postJson(self::ROUTE, $payload);
    }

    /**
     * R3.3, R4.6 — Clé absente → 422 et aucun Report créé.
     */
    public function test_missing_key_rejected_without_creating_report(): void
    {
        $this->postReport([], $this->validPayload())
            ->assertStatus(422);

        $this->assertDatabaseCount('reports', 0);
    }

    /**
     * R3.3, R4.6 — Clé vide/espaces → 422 et aucun Report créé.
     */
    public function test_whitespace_key_rejected_without_creating_report(): void
    {
        $this->postReport(['X-Idempotency-Key' => '   '], $this->validPayload())
            ->assertStatus(422);

        $this->assertDatabaseCount('reports', 0);
    }

    /**
     * R3.3, R4.6 — Clé de plus de 128 caractères → 422 et aucun Report créé.
     */
    public function test_too_long_key_rejected_without_creating_report(): void
    {
        $this->postReport(['X-Idempotency-Key' => str_repeat('a', 129)], $this->validPayload())
            ->assertStatus(422);

        $this->assertDatabaseCount('reports', 0);
    }

    /**
     * R3.2 — Première requête valide → 201, Report créé, clé 'completed' stockée.
     */
    public function test_first_valid_request_creates_report_and_completed_key(): void
    {
        $response = $this->postReport(['X-Idempotency-Key' => 'key-201'], $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.title', 'Déchet sauvage');

        $reportId = $response->json('data.id');

        $this->assertDatabaseCount('reports', 1);

        $this->assertDatabaseHas('idempotency_keys', [
            'key' => 'key-201',
            'status' => 'completed',
            'response_status' => 201,
            'report_id' => $reportId,
        ]);
    }

    /**
     * R4.1, R4.2 — Rejeu : même clé + même corps → réponse identique, un seul Report.
     */
    public function test_replay_same_key_same_body_returns_identical_response(): void
    {
        $payload = $this->validPayload();

        $first = $this->postReport(['X-Idempotency-Key' => 'key-replay'], $payload)
            ->assertCreated();

        $second = $this->postReport(['X-Idempotency-Key' => 'key-replay'], $payload);

        $this->assertSame($first->getStatusCode(), $second->getStatusCode());
        $this->assertSame($first->getContent(), $second->getContent());

        // Un seul Report en base malgré deux requêtes (R4.1).
        $this->assertDatabaseCount('reports', 1);
    }

    /**
     * R4.5 — Même clé + corps différent → 422, Report initial inchangé.
     */
    public function test_same_key_different_body_conflicts(): void
    {
        $first = $this->postReport(['X-Idempotency-Key' => 'key-conflict'], $this->validPayload())
            ->assertCreated();

        $this->postReport(
            ['X-Idempotency-Key' => 'key-conflict'],
            $this->validPayload(['title' => 'Titre totalement différent'])
        )->assertStatus(422);

        // Le Report initial n'est pas modifié et reste unique.
        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseHas('reports', [
            'id' => $first->json('data.id'),
            'title' => 'Déchet sauvage',
        ]);
    }

    /**
     * R4.8 — Clé expirée → traitée comme nouvelle (nouveau Report créé).
     */
    public function test_expired_key_is_treated_as_new(): void
    {
        $payload = $this->validPayload();

        $this->postReport(['X-Idempotency-Key' => 'key-expire'], $payload)
            ->assertCreated();

        $this->assertDatabaseCount('reports', 1);

        // Vieillissement forcé de l'enregistrement au-delà du TTL (24 h).
        DB::table('idempotency_keys')
            ->where('key', 'key-expire')
            ->update(['created_at' => now()->subDays(2)]);

        // Nouvelle requête avec la même clé → considérée comme un premier traitement.
        $this->postReport(['X-Idempotency-Key' => 'key-expire'], $payload)
            ->assertCreated();

        $this->assertDatabaseCount('reports', 2);
    }

    /**
     * R3.7, R4.7 — Traitement concurrent en cours (status 'processing') → 409, aucun Report.
     */
    public function test_in_flight_processing_key_returns_conflict(): void
    {
        IdempotencyKey::create([
            'key' => 'key-processing',
            'request_fingerprint' => hash('sha256', 'peu-importe'),
            'status' => 'processing',
        ]);

        $this->postReport(['X-Idempotency-Key' => 'key-processing'], $this->validPayload())
            ->assertStatus(409);

        $this->assertDatabaseCount('reports', 0);
    }
}
