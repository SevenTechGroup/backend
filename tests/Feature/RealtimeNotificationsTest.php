<?php

namespace Tests\Feature;

use App\Events\NotificationChanged;
use App\Models\Notification;
use App\Models\User;
use App\Observers\NotificationObserver;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_realtime_configuration_is_only_exposed_to_an_authenticated_user(): void
    {
        $this->getJson('/api/realtime/config')->assertUnauthorized();

        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'public-app-key');
        config()->set('broadcasting.connections.reverb.options.host', 'ws.example.test');
        config()->set('broadcasting.connections.reverb.options.port', 443);
        config()->set('broadcasting.connections.reverb.options.scheme', 'https');

        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson('/api/realtime/config')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.app_key', 'public-app-key')
            ->assertJsonPath('data.host', 'ws.example.test')
            ->assertJsonPath('data.auth_endpoint', 'http://localhost/api/broadcasting/auth')
            ->assertJsonMissing(['secret' => config('broadcasting.connections.reverb.secret')]);
    }

    public function test_user_can_only_authorize_their_own_private_notification_channel(): void
    {
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'public-app-key');
        config()->set('broadcasting.connections.reverb.secret', 'private-app-secret');
        config()->set('broadcasting.connections.reverb.app_id', 'app-id');
        config()->set('broadcasting.connections.reverb.options.host', 'ws.example.test');

        Broadcast::purge();
        Broadcast::setDefaultDriver('reverb');
        require base_path('routes/channels.php');

        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $payload = ['socket_id' => '1234.5678'];

        $this->actingAs($user, 'api')
            ->postJson('/api/broadcasting/auth', [
                ...$payload,
                'channel_name' => 'private-users.'.$user->id,
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($user, 'api')
            ->postJson('/api/broadcasting/auth', [
                ...$payload,
                'channel_name' => 'private-users.'.$otherUser->id,
            ])
            ->assertForbidden();
    }

    public function test_notification_event_has_a_private_channel_and_explicit_payload(): void
    {
        $notification = new Notification([
            'user_id' => 42,
            'message' => 'Le dossier a été affecté à votre équipe.',
            'is_read' => false,
        ]);
        $notification->id = 8;
        $notification->created_at = Carbon::parse('2026-07-21T16:00:00Z');
        $notification->updated_at = Carbon::parse('2026-07-21T16:00:00Z');

        $event = new NotificationChanged($notification, 'created');

        $this->assertSame('notification.changed', $event->broadcastAs());
        $this->assertSame('private-users.42', $event->broadcastOn()[0]->name);
        $this->assertSame([
            'action' => 'created',
            'notification' => [
                'id' => 8,
                'user_id' => 42,
                'message' => 'Le dossier a été affecté à votre équipe.',
                'is_read' => false,
                'created_at' => '2026-07-21T16:00:00.000000Z',
                'updated_at' => '2026-07-21T16:00:00.000000Z',
            ],
        ], $event->broadcastWith());
    }

    public function test_observer_broadcasts_changes_and_waits_for_the_database_commit(): void
    {
        Event::fake([NotificationChanged::class]);

        $notification = new Notification([
            'user_id' => 9,
            'message' => 'Un nouveau signalement nécessite votre attention.',
            'is_read' => false,
        ]);
        $notification->id = 18;

        $observer = new NotificationObserver;
        $observer->created($notification);

        $this->assertInstanceOf(ShouldHandleEventsAfterCommit::class, $observer);
        Event::assertDispatched(
            NotificationChanged::class,
            fn (NotificationChanged $event): bool => $event->notification->id === 18
                && $event->action === 'created',
        );
    }
}
