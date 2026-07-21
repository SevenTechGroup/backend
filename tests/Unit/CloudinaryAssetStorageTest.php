<?php

namespace Tests\Unit;

use App\Services\CloudinaryAssetStorage;
use Tests\TestCase;

class CloudinaryAssetStorageTest extends TestCase
{
    public function test_it_generates_a_signed_url_for_an_authenticated_image(): void
    {
        config()->set('services.cloudinary.cloud_name', 'demo');
        config()->set('services.cloudinary.api_key', 'api-key');
        config()->set('services.cloudinary.api_secret', 'api-secret');
        config()->set('services.cloudinary.ca_bundle');

        $url = (new CloudinaryAssetStorage)->signedUrl(
            'sahel-signal/reports/evidence-123',
            'image',
            'authenticated',
            'jpg',
        );

        $this->assertStringStartsWith(
            'https://res.cloudinary.com/demo/image/authenticated/s--',
            $url,
        );
        $this->assertStringContainsString(
            '/v1/sahel-signal/reports/evidence-123.jpg',
            $url,
        );
    }
}
