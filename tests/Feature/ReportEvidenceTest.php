<?php

namespace Tests\Feature;

use App\Contracts\AssetStorage;
use App\Models\Category;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ReportEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private User $citizen;

    private Category $category;

    private Territory $territory;

    private FakeAssetStorage $assetStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->citizen = User::factory()->create(['role' => 'citizen']);
        $this->category = Category::create([
            'name' => 'Inondation',
            'slug' => 'inondation-evidence',
            'severity' => 'high',
            'is_active' => true,
        ]);
        $this->territory = Territory::create([
            'name' => 'Dakar Plateau',
            'code' => 'DKR-EVIDENCE',
            'is_active' => true,
        ]);
        $this->assetStorage = new FakeAssetStorage;
        $this->app->instance(AssetStorage::class, $this->assetStorage);
    }

    public function test_photo_and_consented_location_are_persisted_with_cloudinary_metadata(): void
    {
        $response = $this->actingAs($this->citizen, 'api')
            ->withHeader('X-Idempotency-Key', 'evidence-photo-location')
            ->post('/api/reports', [
                ...$this->validReportPayload(),
                'photo' => UploadedFile::fake()->image('route-inondee.png', 900, 600)->size(800),
                'coordinates' => [
                    'latitude' => 14.7167,
                    'longitude' => -17.4677,
                    'accuracy' => 18.4,
                ],
                'location_consent_accepted' => true,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.provider', 'cloudinary')
            ->assertJsonPath('data.attachments.0.delivery_type', 'authenticated')
            ->assertJsonPath('data.location.latitude', 14.7167)
            ->assertJsonMissingPath('data.attachments.0.provider_public_id')
            ->assertJsonMissingPath('data.attachments.0.secure_url');

        $reportId = $response->json('data.id');

        $this->assertSame('sahel-signal/reports', $this->assetStorage->uploadedFolder);
        $this->assertSame('route-inondee.png', $this->assetStorage->uploadedFile?->getClientOriginalName());
        $this->assertDatabaseHas('attachments', [
            'report_id' => $reportId,
            'provider' => 'cloudinary',
            'provider_public_id' => 'sahel-signal/reports/evidence-123',
            'delivery_type' => 'authenticated',
        ]);
        $this->assertDatabaseHas('report_locations', [
            'report_id' => $reportId,
            'source' => 'gps',
        ]);
        $this->assertDatabaseHas('consent_records', [
            'report_id' => $reportId,
            'user_id' => $this->citizen->id,
            'consent_type' => 'precise_location',
        ]);
    }

    public function test_coordinates_require_explicit_consent(): void
    {
        $this->actingAs($this->citizen, 'api')
            ->withHeader('X-Idempotency-Key', 'evidence-location-without-consent')
            ->post('/api/reports', [
                ...$this->validReportPayload(),
                'coordinates' => [
                    'latitude' => 14.7167,
                    'longitude' => -17.4677,
                    'accuracy' => 20,
                ],
                'location_consent_accepted' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location_consent_accepted');

        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('report_locations', 0);
    }

    public function test_invalid_or_oversized_photo_is_rejected_before_cloud_upload(): void
    {
        $this->actingAs($this->citizen, 'api')
            ->withHeader('X-Idempotency-Key', 'evidence-invalid-file-type')
            ->post('/api/reports', [
                ...$this->validReportPayload(),
                'photo' => UploadedFile::fake()->create('preuve.txt', 20, 'text/plain'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        $this->actingAs($this->citizen, 'api')
            ->withHeader('X-Idempotency-Key', 'evidence-oversized-photo')
            ->post('/api/reports', [
                ...$this->validReportPayload(),
                'photo' => UploadedFile::fake()->image('preuve.jpg')->size(1600),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        $this->assertNull($this->assetStorage->uploadedFile);
    }

    private function validReportPayload(): array
    {
        return [
            'title' => 'Route inondée avec preuve',
            'description' => 'La route est entièrement bloquée par les eaux depuis plusieurs heures.',
            'category_id' => $this->category->id,
            'territory_id' => $this->territory->id,
            'location_text' => 'Près du marché central',
            'priority' => 'high',
        ];
    }
}

class FakeAssetStorage implements AssetStorage
{
    public ?UploadedFile $uploadedFile = null;

    public ?string $uploadedFolder = null;

    public function upload(UploadedFile $file, string $folder): array
    {
        $this->uploadedFile = $file;
        $this->uploadedFolder = $folder;

        return [
            'provider' => 'cloudinary',
            'provider_asset_id' => 'asset-123',
            'provider_public_id' => 'sahel-signal/reports/evidence-123',
            'resource_type' => 'image',
            'delivery_type' => 'authenticated',
            'format' => 'jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => $file->getClientOriginalName(),
            'bytes' => 450000,
            'width' => 900,
            'height' => 600,
            'secure_url' => 'https://res.cloudinary.com/demo/image/authenticated/evidence-123.jpg',
        ];
    }

    public function delete(string $publicId, string $resourceType, string $deliveryType): void
    {
        // No-op in feature tests.
    }
}
