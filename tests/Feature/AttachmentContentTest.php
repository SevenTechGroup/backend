<?php

namespace Tests\Feature;

use App\Contracts\AssetStorage;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Report;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AttachmentContentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Attachment $attachment;

    private AttachmentDeliveryFake $assetStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'citizen']);
        $category = Category::create([
            'name' => 'Voirie',
            'slug' => 'voirie-photo',
            'severity' => 'high',
            'is_active' => true,
        ]);
        $territory = Territory::create([
            'name' => 'Pikine',
            'code' => 'PKN-PHOTO',
            'is_active' => true,
        ]);
        $report = Report::create([
            'user_id' => $this->owner->id,
            'category_id' => $category->id,
            'territory_id' => $territory->id,
            'title' => 'Nid-de-poule dangereux',
            'description' => 'Un nid-de-poule profond présente un danger immédiat pour la circulation.',
            'priority' => 'high',
            'status' => 'received',
        ]);
        $this->attachment = Attachment::create([
            'report_id' => $report->id,
            'provider' => 'cloudinary',
            'provider_asset_id' => 'asset-123',
            'provider_public_id' => 'sahel-signal/reports/evidence-123',
            'resource_type' => 'image',
            'delivery_type' => 'authenticated',
            'format' => 'jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'preuve-terrain.jpg',
            'bytes' => 12,
            'width' => 900,
            'height' => 600,
            'secure_url' => 'https://res.cloudinary.com/demo/image/authenticated/evidence-123.jpg',
        ]);

        $this->assetStorage = new AttachmentDeliveryFake;
        $this->app->instance(AssetStorage::class, $this->assetStorage);
        Http::preventStrayRequests();
    }

    public function test_owner_can_view_private_attachment_through_authenticated_api(): void
    {
        Http::fake([
            $this->assetStorage->url => Http::response('jpeg-contents', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $this->actingAs($this->owner, 'api')
            ->get("/api/attachments/{$this->attachment->id}/content")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'max-age=900, no-transform, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertContent('jpeg-contents');

        $this->assertSame('sahel-signal/reports/evidence-123', $this->assetStorage->publicId);
        $this->assertSame('authenticated', $this->assetStorage->deliveryType);
        Http::assertSentCount(1);
    }

    public function test_other_citizen_cannot_view_private_attachment(): void
    {
        $otherCitizen = User::factory()->create(['role' => 'citizen']);

        $this->actingAs($otherCitizen, 'api')
            ->getJson("/api/attachments/{$this->attachment->id}/content")
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_attachment_content_requires_authentication(): void
    {
        $this->getJson("/api/attachments/{$this->attachment->id}/content")
            ->assertUnauthorized();

        Http::assertNothingSent();
    }
}

class AttachmentDeliveryFake implements AssetStorage
{
    public string $url = 'https://res.cloudinary.com/demo/image/authenticated/s--signature--/evidence-123.jpg';

    public ?string $publicId = null;

    public ?string $deliveryType = null;

    public function upload(UploadedFile $file, string $folder): array
    {
        throw new \LogicException('Upload not expected in attachment delivery tests.');
    }

    public function delete(string $publicId, string $resourceType, string $deliveryType): void
    {
        throw new \LogicException('Delete not expected in attachment delivery tests.');
    }

    public function signedUrl(
        string $publicId,
        string $resourceType,
        string $deliveryType,
        ?string $format = null,
    ): string {
        $this->publicId = $publicId;
        $this->deliveryType = $deliveryType;

        return $this->url;
    }
}
