<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Report;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $citizen;

    private User $agent;

    private User $manager;

    private Category $category;

    private Territory $territory;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Déchets',
            'slug' => 'dechets',
            'severity' => 'medium',
            'description' => 'Déchets',
            'is_active' => true,
        ]);
        $this->territory = Territory::create([
            'name' => 'Dakar',
            'code' => 'DKR',
            'is_active' => true,
        ]);
        $this->citizen = User::factory()->create(['role' => 'citizen']);
        $this->agent = User::factory()->create(['role' => 'agent']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->report = Report::create([
            'user_id' => $this->citizen->id,
            'category_id' => $this->category->id,
            'territory_id' => $this->territory->id,
            'title' => 'Signalement de test',
            'description' => 'Description suffisamment longue pour être valide.',
            'priority' => 'medium',
            'status' => 'received',
        ]);
    }

    public function test_report_creation_rejects_invalid_domain_values(): void
    {
        $this->actingAs($this->citizen, 'api')
            ->withHeader('X-Idempotency-Key', 'validation-invalid-domain-values')
            ->postJson('/api/reports', [
                'title' => 'Test',
                'description' => 'Trop courte',
                'category_id' => $this->category->id,
                'territory_id' => $this->territory->id,
                'priority' => 'critical',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description', 'priority']);
    }

    public function test_report_creation_rejects_inactive_references(): void
    {
        $inactiveCategory = Category::create([
            'name' => 'Inactive',
            'slug' => 'inactive',
            'severity' => 'low',
            'is_active' => false,
        ]);
        $inactiveTerritory = Territory::create([
            'name' => 'Inactive',
            'code' => 'INA',
            'is_active' => false,
        ]);

        $this->actingAs($this->citizen, 'api')
            ->withHeader('X-Idempotency-Key', 'validation-inactive-references')
            ->postJson('/api/reports', [
                'title' => 'Référence inactive',
                'description' => 'Description suffisamment longue pour être valide.',
                'category_id' => $inactiveCategory->id,
                'territory_id' => $inactiveTerritory->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'territory_id']);
    }

    public function test_report_status_transitions_are_enforced(): void
    {
        $this->actingAs($this->manager, 'api')
            ->putJson("/api/reports/{$this->report->id}", ['status' => 'hacked'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($this->manager, 'api')
            ->putJson("/api/reports/{$this->report->id}", ['status' => 'resolved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($this->manager, 'api')
            ->putJson("/api/reports/{$this->report->id}", ['status' => 'in_progress'])
            ->assertOk();

        $this->actingAs($this->manager, 'api')
            ->putJson("/api/reports/{$this->report->id}", ['status' => 'resolved'])
            ->assertOk();
    }

    public function test_assignment_target_and_transitions_are_enforced(): void
    {
        $this->actingAs($this->manager, 'api')
            ->postJson('/api/assignments', [
                'report_id' => $this->report->id,
                'user_id' => $this->citizen->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $assignment = $this->actingAs($this->manager, 'api')
            ->postJson('/api/assignments', [
                'report_id' => $this->report->id,
                'user_id' => $this->agent->id,
            ])
            ->assertCreated();

        $assignmentId = $assignment->json('data.id');

        $this->actingAs($this->agent, 'api')
            ->putJson("/api/assignments/{$assignmentId}", ['status' => 'completed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($this->agent, 'api')
            ->putJson("/api/assignments/{$assignmentId}", ['status' => 'in_progress'])
            ->assertOk();

        $this->actingAs($this->agent, 'api')
            ->putJson("/api/assignments/{$assignmentId}", ['status' => 'completed'])
            ->assertOk();
    }

    public function test_public_registration_cannot_escalate_role(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Public User',
            'email' => 'public@example.com',
            'password' => 'password123',
            'role' => 'manager',
        ])
            ->assertCreated()
            ->assertJsonPath('user.role', 'citizen');

        $this->assertDatabaseHas('users', [
            'email' => 'public@example.com',
            'role' => 'citizen',
        ]);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'missing@example.com',
                'password' => 'password123',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'password123',
        ])->assertTooManyRequests();
    }
}
