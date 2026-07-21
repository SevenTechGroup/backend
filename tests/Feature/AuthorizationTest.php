<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Category;
use App\Models\Notification;
use App\Models\Report;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $citizen;

    private User $otherCitizen;

    private User $agent;

    private User $otherAgent;

    private User $manager;

    private Report $citizenReport;

    private Report $otherReport;

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

        $this->citizen = User::factory()->create(['role' => 'citizen']);
        $this->otherCitizen = User::factory()->create(['role' => 'citizen']);
        $this->agent = User::factory()->create(['role' => 'agent']);
        $this->otherAgent = User::factory()->create(['role' => 'agent']);
        $this->manager = User::factory()->create(['role' => 'manager']);

        $this->citizenReport = $this->createReport($this->citizen, $category, $territory, 'Citizen report');
        $this->otherReport = $this->createReport($this->otherCitizen, $category, $territory, 'Other report');
    }

    public function test_citizen_only_sees_their_own_reports(): void
    {
        $this->actingAs($this->citizen, 'api')
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $this->citizenReport->id])
            ->assertJsonMissing(['title' => $this->otherReport->title]);

        $this->actingAs($this->citizen, 'api')
            ->getJson("/api/reports/{$this->otherReport->id}")
            ->assertForbidden();
    }

    public function test_citizen_cannot_update_reports_or_manage_assignments(): void
    {
        $this->actingAs($this->citizen, 'api')
            ->putJson("/api/reports/{$this->citizenReport->id}", ['status' => 'in_progress'])
            ->assertForbidden();

        $this->actingAs($this->citizen, 'api')
            ->getJson('/api/assignments')
            ->assertForbidden();

        $this->actingAs($this->citizen, 'api')
            ->postJson('/api/assignments', [
                'report_id' => $this->citizenReport->id,
                'user_id' => $this->agent->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('assignments', 0);
    }

    public function test_manager_can_update_reports_and_create_assignments(): void
    {
        $this->actingAs($this->manager, 'api')
            ->putJson("/api/reports/{$this->otherReport->id}", [
                'status' => 'in_progress',
                'priority' => 'high',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.priority', 'high');

        $this->actingAs($this->manager, 'api')
            ->postJson('/api/assignments', [
                'report_id' => $this->otherReport->id,
                'user_id' => $this->agent->id,
                'notes' => 'Prise en charge',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $this->agent->id);
    }

    public function test_only_manager_can_list_agents_for_assignment_creation(): void
    {
        $this->actingAs($this->manager, 'api')
            ->getJson('/api/agents')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'id' => $this->agent->id,
                'name' => $this->agent->name,
                'role' => 'agent',
            ])
            ->assertJsonMissing(['id' => $this->citizen->id]);

        $this->actingAs($this->agent, 'api')
            ->getJson('/api/agents')
            ->assertForbidden();

        $this->actingAs($this->citizen, 'api')
            ->getJson('/api/agents')
            ->assertForbidden();
    }

    public function test_agent_only_sees_and_updates_their_assignments(): void
    {
        $ownAssignment = Assignment::create([
            'report_id' => $this->citizenReport->id,
            'user_id' => $this->agent->id,
            'status' => 'assigned',
        ]);
        $otherAssignment = Assignment::create([
            'report_id' => $this->otherReport->id,
            'user_id' => $this->otherAgent->id,
            'status' => 'assigned',
        ]);

        $this->actingAs($this->agent, 'api')
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['title' => $this->citizenReport->title])
            ->assertJsonMissing(['title' => $this->otherReport->title]);

        $this->actingAs($this->agent, 'api')
            ->getJson("/api/reports/{$this->citizenReport->id}")
            ->assertOk();

        $this->actingAs($this->agent, 'api')
            ->getJson("/api/reports/{$this->otherReport->id}")
            ->assertForbidden();

        $this->actingAs($this->agent, 'api')
            ->putJson("/api/reports/{$this->citizenReport->id}", ['status' => 'in_progress'])
            ->assertOk();

        $this->actingAs($this->agent, 'api')
            ->putJson("/api/reports/{$this->otherReport->id}", ['status' => 'in_progress'])
            ->assertForbidden();

        $this->actingAs($this->agent, 'api')
            ->getJson('/api/assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $ownAssignment->id])
            ->assertJsonMissing(['id' => $otherAssignment->id]);

        $this->actingAs($this->agent, 'api')
            ->putJson("/api/assignments/{$ownAssignment->id}", ['status' => 'in_progress'])
            ->assertOk();

        $this->actingAs($this->agent, 'api')
            ->putJson("/api/assignments/{$otherAssignment->id}", ['status' => 'in_progress'])
            ->assertForbidden();
    }

    public function test_user_cannot_modify_another_users_notification(): void
    {
        $notification = Notification::create([
            'user_id' => $this->otherCitizen->id,
            'message' => 'Notification privée',
            'is_read' => false,
        ]);

        $this->actingAs($this->citizen, 'api')
            ->postJson("/api/notifications/{$notification->id}/read")
            ->assertForbidden();

        $this->assertFalse($notification->fresh()->is_read);
    }

    public function test_dashboard_statistics_are_scoped_by_role(): void
    {
        Assignment::create([
            'report_id' => $this->otherReport->id,
            'user_id' => $this->agent->id,
            'status' => 'assigned',
        ]);

        $this->actingAs($this->citizen, 'api')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_reports', 1)
            ->assertJsonPath('data.total_assignments', 0);

        $this->actingAs($this->agent, 'api')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_reports', 1)
            ->assertJsonPath('data.total_assignments', 1);

        $this->actingAs($this->manager, 'api')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_reports', 2)
            ->assertJsonPath('data.total_assignments', 1);
    }

    public function test_unauthenticated_api_request_is_always_json_401(): void
    {
        $this->get('/api/reports')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_unknown_role_is_never_treated_as_privileged(): void
    {
        $unknownRoleUser = User::factory()->create(['role' => 'unexpected']);

        $this->actingAs($unknownRoleUser, 'api')
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($unknownRoleUser, 'api')
            ->getJson('/api/assignments')
            ->assertForbidden();

        $this->actingAs($unknownRoleUser, 'api')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_reports', 0)
            ->assertJsonPath('data.total_assignments', 0);
    }

    private function createReport(User $owner, Category $category, Territory $territory, string $title): Report
    {
        return Report::create([
            'user_id' => $owner->id,
            'category_id' => $category->id,
            'territory_id' => $territory->id,
            'title' => $title,
            'description' => 'Description suffisamment longue pour le signalement.',
            'location_text' => 'Dakar',
            'priority' => 'medium',
            'status' => 'received',
        ]);
    }
}
