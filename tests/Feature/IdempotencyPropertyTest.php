<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Models\Category;
use App\Models\Report;
use App\Models\Territory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Tests basés sur propriétés (style génératif en PHPUnit pur, sans librairie PBT)
 * pour le middleware d'idempotence sur la création de Report.
 *
 * On réutilise le motif d'intégration de IdempotencyTest : une route ad-hoc
 * authentifiée applique le middleware sous test puis exécute le vrai
 * ReportController::store (donc le vrai ReportService). Les compteurs de Report
 * sont mesurés en DELTA par itération pour rester assertables sur de nombreuses
 * itérations (RefreshDatabase ne réinitialise qu'entre méthodes de test).
 *
 * Propriétés couvertes (design.md) :
 *  - Property 1 : Unicité du signalement (R4.3, R4.4)
 *  - Property 2 : Déterminisme du rejeu (R4.1, R4.2)
 *  - Property 3 : Validation de la clé (R3.3, R4.6)
 *  - Property 4 : Détection de conflit (R4.5)
 *  - Property 9 : Expiration (R3.5, R4.8)
 *
 * Validates: Requirements 3.3, 3.5, 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.8
 */
class IdempotencyPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/_test/reports';

    private const HEADER = 'X-Idempotency-Key';

    /**
     * Alphabet des caractères d'une clé « bien formée » (le middleware ne
     * contraint que la longueur après trim, pas l'ensemble de caractères).
     */
    private const KEY_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';

    private const MAX_KEY_LENGTH = 128;

    // Nombre d'itérations par propriété.
    private const ITER_UNICITE = 30;

    private const ITER_REPLAY = 30;

    private const ITER_VALIDATION = 60;

    private const ITER_CONFLICT = 30;

    private const ITER_EXPIRATION = 20;

    private string $token;

    private int $categoryId;

    private int $territoryId;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->postJson('/api/auth/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
        ])->assertCreated();

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'password123',
        ])->assertOk()->json('token');

        Route::middleware(['auth:api', IdempotencyMiddleware::class])
            ->post(self::ROUTE, [ReportController::class, 'store']);
    }

    /**
     * Property 1 — Unicité : pour une clé valide aléatoire et un corps fixe,
     * envoyer la requête N fois (N ∈ [2, 6]) persiste EXACTEMENT un Report.
     *
     * Validates: Requirements 4.3, 4.4
     */
    public function test_property_unicite_single_report_for_repeated_identical_requests(): void
    {
        $checked = 0;

        for ($i = 0; $i < self::ITER_UNICITE; $i++) {
            $key = $this->uniqueKey('p1', $i);
            $payload = $this->validPayload();
            $repeats = random_int(2, 6);

            $before = Report::count();

            for ($n = 0; $n < $repeats; $n++) {
                $this->postReport([self::HEADER => $key], $payload);
            }

            $delta = Report::count() - $before;

            $this->assertSame(
                1,
                $delta,
                "Property 1 violée : {$repeats} requêtes identiques ont persisté {$delta} Report(s) au lieu de 1. "
                .$this->describe($key, $payload, $i),
            );

            $checked++;
        }

        $this->assertSame(self::ITER_UNICITE, $checked);
    }

    /**
     * Property 2 — Déterminisme du rejeu : la première réponse et chaque rejeu
     * partagent un code de statut ET un corps identiques.
     *
     * Validates: Requirements 4.1, 4.2
     */
    public function test_property_replay_is_deterministic(): void
    {
        $checked = 0;

        for ($i = 0; $i < self::ITER_REPLAY; $i++) {
            $key = $this->uniqueKey('p2', $i);
            $payload = $this->validPayload();
            $replays = random_int(2, 6);

            $first = $this->postReport([self::HEADER => $key], $payload);
            $firstStatus = $first->getStatusCode();
            $firstBody = $first->getContent();

            for ($n = 0; $n < $replays; $n++) {
                $replay = $this->postReport([self::HEADER => $key], $payload);

                $this->assertSame(
                    $firstStatus,
                    $replay->getStatusCode(),
                    "Property 2 violée : rejeu #{$n} a un code {$replay->getStatusCode()} ≠ {$firstStatus}. "
                    .$this->describe($key, $payload, $i),
                );
                $this->assertSame(
                    $firstBody,
                    $replay->getContent(),
                    "Property 2 violée : rejeu #{$n} a un corps différent du premier traitement. "
                    .$this->describe($key, $payload, $i),
                );
            }

            $checked++;
        }

        $this->assertSame(self::ITER_REPLAY, $checked);
    }

    /**
     * Property 3 — Validation de la clé : avec un corps TOUJOURS valide (seule
     * la clé peut causer un 422), une requête est acceptée (201) si et seulement
     * si la longueur de trim(clé) ∈ [1, 128] ; sinon 422 sans Report créé.
     *
     * Validates: Requirements 3.3, 4.6
     */
    public function test_property_key_validation_accepts_iff_trimmed_length_in_range(): void
    {
        $checked = 0;

        for ($i = 0; $i < self::ITER_VALIDATION; $i++) {
            $key = $this->generateValidationKey($i);
            $this->assertKeyValidationInvariant($key, $i);
            $checked++;
        }

        $this->assertSame(self::ITER_VALIDATION, $checked);
    }

    /**
     * Property 3 — graines fixes couvrant les cas limites de longueur.
     */
    public function test_property_key_validation_fixed_edge_seeds(): void
    {
        $seeds = [
            '',                                              // vide → 422
            ' ',                                             // 1 espace → trim vide → 422
            "\t  \t",                                        // espaces variés → trim vide → 422
            $this->randomValidString(1),                     // longueur 1 → accepté
            $this->randomValidString(self::MAX_KEY_LENGTH),  // longueur 128 → accepté
            $this->randomValidString(self::MAX_KEY_LENGTH + 1), // 129 → 422
            $this->randomValidString(160),                   // 160 → 422
            '  '.$this->randomValidString(10).'  ',          // cœur valide entouré d'espaces → accepté
        ];

        foreach ($seeds as $seedIndex => $key) {
            // Décale l'indice pour garantir l'unicité des clés valides des graines.
            $this->assertKeyValidationInvariant($key, 10_000 + $seedIndex);
        }
    }

    /**
     * Property 4 — Conflit : une clé traitée avec le corps A (201) puis réutilisée
     * avec un corps B (sha256 garanti différent) renvoie 422 et laisse exactement
     * un Report.
     *
     * Validates: Requirements 4.5
     */
    public function test_property_conflict_same_key_different_body(): void
    {
        $checked = 0;

        for ($i = 0; $i < self::ITER_CONFLICT; $i++) {
            $key = $this->uniqueKey('p4', $i);
            $bodyA = $this->validPayload(['title' => 'Titre A '.$i]);
            $bodyB = $this->validPayload(['title' => 'Titre B totalement different '.$i]);

            // Garantie d'empreintes différentes (corps JSON distincts).
            $this->assertNotSame(
                hash('sha256', json_encode($bodyA)),
                hash('sha256', json_encode($bodyB)),
                'Pré-condition du test : les corps A et B doivent avoir des empreintes distinctes.',
            );

            $before = Report::count();

            $first = $this->postReport([self::HEADER => $key], $bodyA);
            $this->assertSame(
                201,
                $first->getStatusCode(),
                'Property 4 : la première requête (corps A) devrait réussir. '.$this->describe($key, $bodyA, $i),
            );

            $second = $this->postReport([self::HEADER => $key], $bodyB);
            $this->assertSame(
                422,
                $second->getStatusCode(),
                'Property 4 violée : corps différent sous la même clé devrait renvoyer 422, obtenu '
                .$second->getStatusCode().'. '.$this->describe($key, $bodyB, $i),
            );

            $delta = Report::count() - $before;
            $this->assertSame(
                1,
                $delta,
                "Property 4 violée : un conflit devrait laisser exactement 1 Report (delta={$delta}). "
                .$this->describe($key, $bodyB, $i),
            );

            $checked++;
        }

        $this->assertSame(self::ITER_CONFLICT, $checked);
    }

    /**
     * Property 9 — Expiration : après un premier succès, vieillir l'enregistrement
     * au-delà du TTL fait traiter la requête suivante comme nouvelle (2e Report).
     *
     * Validates: Requirements 3.5, 4.8
     */
    public function test_property_expiration_treated_as_new_after_ttl(): void
    {
        $checked = 0;
        $ttl = (int) config('idempotency.ttl', 86400);

        for ($i = 0; $i < self::ITER_EXPIRATION; $i++) {
            $key = $this->uniqueKey('p9', $i);
            $payload = $this->validPayload();

            $before = Report::count();

            $this->postReport([self::HEADER => $key], $payload)
                ->assertStatus(201);

            // Vieillissement forcé de l'enregistrement au-delà du TTL configuré.
            DB::table('idempotency_keys')
                ->where('key', trim($key))
                ->update(['created_at' => now()->subSeconds($ttl + 10)]);

            $second = $this->postReport([self::HEADER => $key], $payload);
            $this->assertSame(
                201,
                $second->getStatusCode(),
                'Property 9 violée : après expiration, la requête devrait être traitée comme nouvelle (201), obtenu '
                .$second->getStatusCode().'. '.$this->describe($key, $payload, $i),
            );

            $delta = Report::count() - $before;
            $this->assertSame(
                2,
                $delta,
                "Property 9 violée : une clé expirée devrait produire un 2e Report (delta={$delta}). "
                .$this->describe($key, $payload, $i),
            );

            $checked++;
        }

        $this->assertSame(self::ITER_EXPIRATION, $checked);
    }

    /**
     * Vérifie l'invariant de Property 3 pour une clé donnée : le corps est
     * toujours valide, donc l'unique cause possible de 422 est la clé.
     */
    private function assertKeyValidationInvariant(string $key, int $iteration): void
    {
        $payload = $this->validPayload(['title' => 'Validation '.$iteration]);
        $trimmedLength = strlen(trim($key));
        $shouldBeAccepted = $trimmedLength >= 1 && $trimmedLength <= self::MAX_KEY_LENGTH;

        $before = Report::count();
        $response = $this->postReport([self::HEADER => $key], $payload);
        $delta = Report::count() - $before;

        $context = $this->describeKey($key, $trimmedLength, $iteration);

        if ($shouldBeAccepted) {
            $this->assertSame(
                201,
                $response->getStatusCode(),
                "Property 3 violée : clé de longueur trim {$trimmedLength} ∈ [1,128] devrait être acceptée (201), obtenu "
                .$response->getStatusCode().". {$context}",
            );
            $this->assertSame(
                1,
                $delta,
                "Property 3 violée : une clé acceptée devrait créer exactement 1 Report (delta={$delta}). {$context}",
            );
        } else {
            $this->assertSame(
                422,
                $response->getStatusCode(),
                "Property 3 violée : clé de longueur trim {$trimmedLength} hors [1,128] devrait renvoyer 422, obtenu "
                .$response->getStatusCode().". {$context}",
            );
            $this->assertSame(
                0,
                $delta,
                "Property 3 violée : une clé invalide ne devrait créer aucun Report (delta={$delta}). {$context}",
            );
        }
    }

    /**
     * Génère une clé pour Property 3 couvrant les familles : valide (1..128),
     * vide, espaces seuls, trop longue (129..160), cœur valide entouré d'espaces,
     * et les bornes exactes 128 / 129.
     */
    private function generateValidationKey(int $iteration): string
    {
        return match (random_int(0, 6)) {
            // Valide, longueur 1..128. Préfixe unique pour éviter tout rejeu inter-itération.
            0 => $this->uniqueKey('p3', $iteration),
            // Vide → trim vide → rejeté.
            1 => '',
            // Espaces seuls → trim vide → rejeté.
            2 => str_repeat(' ', random_int(1, 8)),
            // Trop long 129..160 → rejeté.
            3 => $this->randomValidString(random_int(self::MAX_KEY_LENGTH + 1, 160)),
            // Cœur valide unique entouré d'espaces → trim valide → accepté.
            4 => '   '.$this->uniqueKey('p3w', $iteration).'   ',
            // Borne exacte : trim length == 128 → accepté (préfixe unique inclus).
            5 => $this->boundaryValidKey($iteration, self::MAX_KEY_LENGTH),
            // Borne exacte : trim length == 129 → rejeté.
            default => $this->randomValidString(self::MAX_KEY_LENGTH + 1),
        };
    }

    /**
     * Construit une clé valide unique de longueur exacte $length (≤ 128) en
     * incorporant l'itération pour l'unicité, complétée par des caractères valides.
     */
    private function boundaryValidKey(int $iteration, int $length): string
    {
        $prefix = 'p3b'.$iteration.'-';
        if (strlen($prefix) >= $length) {
            $prefix = substr($prefix, 0, $length - 1).'-';
        }

        return $prefix.$this->randomValidString($length - strlen($prefix));
    }

    /**
     * Clé bien formée unique : préfixe + itération + segment aléatoire valide.
     * Garantit l'absence de collision entre itérations (donc pas de rejeu fortuit).
     */
    private function uniqueKey(string $prefix, int $iteration): string
    {
        return $prefix.'-'.$iteration.'-'.$this->randomValidString(random_int(6, 24));
    }

    /**
     * Chaîne composée exclusivement de caractères de l'ensemble « bien formé ».
     */
    private function randomValidString(int $length): string
    {
        $max = strlen(self::KEY_ALPHABET) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= self::KEY_ALPHABET[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * Charge utile valide pour créer un Report (description 20..1000 car.).
     */
    private function validPayload(array $overrides = []): array
    {
        $priorities = ['low', 'medium', 'high'];

        return array_merge([
            'title' => 'Signalement '.random_int(1, 1_000_000),
            'description' => $this->randomDescription(),
            'category_id' => $this->categoryId,
            'territory_id' => $this->territoryId,
            'location_text' => 'Quartier Centre',
            'priority' => $priorities[random_int(0, 2)],
        ], $overrides);
    }

    /**
     * Génère une description de longueur aléatoire respectant la contrainte
     * de validation (20..1000 caractères).
     */
    private function randomDescription(): string
    {
        $length = random_int(20, 1000);
        $alphabet = 'abcdefghijklmnopqrstuvwxyz ';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
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
     * Décrit une itération (clé + empreinte du corps) pour les messages d'échec.
     */
    private function describe(string $key, array $payload, int $iteration): string
    {
        return sprintf(
            'Offending input → itération=%d, clé="%s" (len=%d, trimLen=%d), hash(corps)=%s',
            $iteration,
            addcslashes($key, "\0..\37"),
            strlen($key),
            strlen(trim($key)),
            substr(hash('sha256', json_encode($payload)), 0, 12),
        );
    }

    /**
     * Décrit une clé pour les messages d'échec de Property 3.
     */
    private function describeKey(string $key, int $trimmedLength, int $iteration): string
    {
        return sprintf(
            'Offending input → itération=%d, clé="%s" (len=%d, trimLen=%d)',
            $iteration,
            addcslashes($key, "\0..\37"),
            strlen($key),
            $trimmedLength,
        );
    }
}
