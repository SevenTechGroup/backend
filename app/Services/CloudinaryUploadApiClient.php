<?php

namespace App\Services;

use Cloudinary\Api\UploadApiClient;
use GuzzleHttp\Client;

final class CloudinaryUploadApiClient extends UploadApiClient
{
    public function __construct(mixed $configuration = null, private readonly ?string $caBundle = null)
    {
        parent::__construct($configuration);
    }

    protected function createHttpClient(): void
    {
        $config = $this->buildHttpClientConfig();

        if ($this->caBundle !== null) {
            $config['verify'] = $this->caBundle;
        }

        $this->httpClient = new Client($config);
    }
}
