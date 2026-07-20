<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Notification;
use App\Models\Report;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SahelSignalCompleteMVPTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

    private User $manager;

    private Territory $territory;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
    }

    private function setupTestData(): void
    {
        $this->territory = Territory::create(['name' => 'Dakar', 'code' => 'DKR', 'is_active' => true]);
        $this->category = Category::create(['name' => 'Déchets', 'slug' => 'dechets', 'severity' => 'medium', 'description' => 'Déchets', 'is_active' => true]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]);

        $this->token = $response->json('token');
        $this->user = User::where('email', 'alice@example.com')->first();

        $this->manager = User::create([
            'name' => 'Manager',
            'email' => 'manager@example.com',
            'password' => bcrypt('password123'),
            'role' => 'manager',
        ]);
    }

    /**
     * Test complet du workflow d'authentification
     */
    public function test_authentication_workflow()
    {
        // Register
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'password123',
        ]);

        $register->assertStatus(201)
            ->assertJsonPath('user.email', 'bob@example.com')
            ->assertJsonStructure(['token', 'user']);

        // Login
        $login = $this->postJson('/api/auth/login', [
            'email' => 'bob@example.com',
            'password' => 'password123',
        ]);

        $login->assertStatus(200)
            ->assertJsonStructure(['token', 'user']);

        $token = $login->json('token');

        // Me
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('user.email', 'bob@example.com');

        // Logout
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertStatus(200);
    }

    /**
     * Test des endpoints sans authentification
     */
    public function test_public_endpoints()
    {
        $this->getJson('/api/territories')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/categories')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /**
     * Test du workflow complet de signalement
     */
    public function test_report_workflow()
    {
        // Créer un signalement
        $report = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/reports', [
                'title' => 'Déchet sauvage',
                'description' => 'Un dépôt de déchets dangereux en centre ville',
                'category_id' => $this->category->id,
                'territory_id' => $this->territory->id,
                'location_text' => 'Quartier Centre',
                'priority' => 'high',
            ])->assertStatus(201)
            ->assertJsonPath('data.title', 'Déchet sauvage')
            ->assertJsonPath('data.status', 'received');

        $reportId = $report->json('data.id');

        // Lister les signalements
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/reports')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Afficher un signalement spécifique
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/reports/$reportId")
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Déchet sauvage');

        // Mettre à jour le statut du signalement
        $this->actingAs($this->manager, 'api')
            ->putJson("/api/reports/$reportId", [
                'status' => 'in_progress',
                'priority' => 'medium',
            ])->assertStatus(200)
            ->assertJsonPath('data.status', 'in_progress');
    }

    /**
     * Test du workflow d'assignation
     */
    public function test_assignment_workflow()
    {
        $report = Report::create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'territory_id' => $this->territory->id,
            'title' => 'Test Report',
            'description' => 'Description',
            'location_text' => 'Location',
            'priority' => 'medium',
            'status' => 'received',
        ]);

        $agent = User::create([
            'name' => 'Agent',
            'email' => 'agent@example.com',
            'password' => bcrypt('password123'),
            'role' => 'agent',
        ]);

        // Créer une assignation
        $assignment = $this->actingAs($this->manager, 'api')
            ->postJson('/api/assignments', [
                'report_id' => $report->id,
                'user_id' => $agent->id,
                'notes' => 'Urgent à traiter',
            ])->assertStatus(201)
            ->assertJsonPath('data.status', 'assigned');

        $assignmentId = $assignment->json('data.id');

        // Lister les assignations
        $this->actingAs($this->manager, 'api')
            ->getJson('/api/assignments')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Mettre à jour une assignation
        $this->actingAs($agent, 'api')
            ->putJson("/api/assignments/$assignmentId", [
                'status' => 'in_progress',
                'notes' => 'En cours de traitement',
            ])->assertStatus(200)
            ->assertJsonPath('data.status', 'in_progress');
    }

    /**
     * Test des notifications
     */
    public function test_notifications()
    {
        $notification = Notification::create([
            'user_id' => $this->user->id,
            'message' => 'Notification de test',
            'is_read' => false,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/notifications')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/notifications/{$notification->id}/read")
            ->assertStatus(200);

        $this->assertTrue($notification->fresh()->is_read);
    }

    /**
     * Test du tableau de bord
     */
    public function test_dashboard()
    {
        Report::create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'territory_id' => $this->territory->id,
            'title' => 'Test Report',
            'description' => 'Description',
            'location_text' => 'Location',
            'priority' => 'medium',
            'status' => 'received',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('data.total_reports', 1)
            ->assertJsonPath('data.my_reports', 1);
    }

    /**
     * Test des erreurs d'authentification
     */
    public function test_authentication_errors()
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ])->assertStatus(401);

        $this->getJson('/api/reports')
            ->assertStatus(401);
    }

    /**
     * Test des validations de données
     */
    public function test_validation_errors()
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/reports', [
                'title' => '',
                'description' => '',
            ])->assertStatus(422);

        $this->postJson('/api/auth/register', [
            'name' => 'Test',
            'email' => 'invalid-email',
            'password' => '123',
        ])->assertStatus(422);
    }

    /**
     * Test de l'intégrité des données
     */
    public function test_data_integrity()
    {
        $report = Report::create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'territory_id' => $this->territory->id,
            'title' => 'Test Report',
            'description' => 'Description',
            'location_text' => 'Location',
            'priority' => 'high',
            'status' => 'received',
        ]);

        $retrieved = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/reports/{$report->id}")
            ->assertStatus(200)
            ->json('data');

        $this->assertEquals($report->id, $retrieved['id']);
        $this->assertEquals('high', $retrieved['priority']);
        $this->assertEquals('received', $retrieved['status']);
    }
}
