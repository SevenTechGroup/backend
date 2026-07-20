<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SahelSignalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_and_reports_workflow(): void
    {
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

        $registerResponse = $this->postJson('/api/auth/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]);

        $registerResponse->assertCreated()
            ->assertJsonPath('user.email', 'alice@example.com');

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]);

        $loginResponse->assertOk()
            ->assertJsonStructure(['token', 'user']);

        $token = $loginResponse->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'alice@example.com');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/territories')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $reportResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Idempotency-Key', 'api-report-create')
            ->postJson('/api/reports', [
                'title' => 'Déchet sauvage',
                'description' => 'Un dépôt sauvage est visible devant la mairie.',
                'category_id' => $category->id,
                'territory_id' => $territory->id,
                'location_text' => 'Quartier Centre',
                'priority' => 'medium',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Déchet sauvage');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $manager = User::factory()->create(['role' => 'manager']);
        $agent = User::factory()->create(['role' => 'agent']);

        $this->actingAs($manager, 'api')
            ->postJson('/api/assignments', [
                'report_id' => $reportResponse->json('data.id'),
                'user_id' => $agent->id,
                'notes' => 'Affectation de test',
            ])
            ->assertCreated();

        $alice = User::where('email', 'alice@example.com')->firstOrFail();

        $this->actingAs($alice, 'api')
            ->getJson('/api/notifications')
            ->assertOk();

        $this->actingAs($alice, 'api')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_reports', 1);
    }
}
