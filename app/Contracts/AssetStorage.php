<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

interface AssetStorage
{
    /**
     * @return array{
     *     provider: string,
     *     provider_asset_id: string|null,
     *     provider_public_id: string,
     *     resource_type: string,
     *     delivery_type: string,
     *     format: string|null,
     *     mime_type: string,
     *     original_filename: string,
     *     bytes: int,
     *     width: int|null,
     *     height: int|null,
     *     secure_url: string
     * }
     */
    public function upload(UploadedFile $file, string $folder): array;

    public function delete(string $publicId, string $resourceType, string $deliveryType): void;

    public function signedUrl(
        string $publicId,
        string $resourceType,
        string $deliveryType,
        ?string $format = null,
    ): string;
}
