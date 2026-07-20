<?php

namespace App\Services;

use Cloudinary\Api\Upload\UploadApi;

final class CloudinaryUploadApi extends UploadApi
{
    public function __construct(mixed $configuration = null, ?string $caBundle = null)
    {
        $this->apiClient = new CloudinaryUploadApiClient($configuration, $caBundle);
    }
}
