<?php

namespace App\Services;

use App\Contracts\AssetStorage;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

class CloudinaryAssetStorage implements AssetStorage
{
    private ?CloudinaryUploadApi $client = null;

    private ?Cloudinary $cloudinary = null;

    public function upload(UploadedFile $file, string $folder): array
    {
        try {
            $result = $this->client()->upload($file->getRealPath(), [
                'resource_type' => 'auto',
                'type' => 'authenticated',
                'folder' => trim($folder, '/'),
                'use_filename' => true,
                'unique_filename' => true,
                'overwrite' => false,
                'tags' => ['sahel-signal', 'report-evidence'],
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Le stockage sécurisé du fichier est momentanément indisponible.',
                previous: $exception,
            );
        }

        return [
            'provider' => 'cloudinary',
            'provider_asset_id' => $result['asset_id'] ?? null,
            'provider_public_id' => (string) $result['public_id'],
            'resource_type' => (string) ($result['resource_type'] ?? 'raw'),
            'delivery_type' => (string) ($result['type'] ?? 'authenticated'),
            'format' => isset($result['format']) ? (string) $result['format'] : null,
            'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'original_filename' => $file->getClientOriginalName(),
            'bytes' => (int) ($result['bytes'] ?? $file->getSize()),
            'width' => isset($result['width']) ? (int) $result['width'] : null,
            'height' => isset($result['height']) ? (int) $result['height'] : null,
            'secure_url' => (string) $result['secure_url'],
        ];
    }

    public function delete(string $publicId, string $resourceType, string $deliveryType): void
    {
        $this->client()->destroy($publicId, [
            'resource_type' => $resourceType,
            'type' => $deliveryType,
            'invalidate' => true,
        ]);
    }

    public function signedUrl(
        string $publicId,
        string $resourceType,
        string $deliveryType,
        ?string $format = null,
    ): string {
        try {
            $asset = match ($resourceType) {
                'image' => $this->cloudinary()->image($publicId),
                'video' => $this->cloudinary()->video($publicId),
                default => $this->cloudinary()->raw($publicId),
            };

            $asset->deliveryType($deliveryType)->signUrl();

            if ($format) {
                $asset->extension($format);
            }

            return (string) $asset->toUrl();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'La preuve photographique est momentanément indisponible.',
                previous: $exception,
            );
        }
    }

    private function client(): CloudinaryUploadApi
    {
        if ($this->client) {
            return $this->client;
        }

        return $this->client = new CloudinaryUploadApi(
            $this->configuration(),
            $this->caBundle(),
        );
    }

    private function cloudinary(): Cloudinary
    {
        return $this->cloudinary ??= new Cloudinary($this->configuration());
    }

    /**
     * @return array{
     *     cloud: array{cloud_name: string, api_key: string, api_secret: string},
     *     url: array{secure: true}
     * }
     */
    private function configuration(): array
    {
        $cloudName = (string) config('services.cloudinary.cloud_name');
        $apiKey = (string) config('services.cloudinary.api_key');
        $apiSecret = (string) config('services.cloudinary.api_secret');

        if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
            throw new RuntimeException(
                'Cloudinary doit être configuré côté serveur avant tout envoi de fichier.',
            );
        }

        return [
            'cloud' => [
                'cloud_name' => $cloudName,
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
            ],
            'url' => ['secure' => true],
        ];
    }

    private function caBundle(): ?string
    {
        $caBundle = trim((string) config('services.cloudinary.ca_bundle'));

        if ($caBundle !== '' && ! is_file($caBundle)) {
            throw new RuntimeException('Le certificat CA configuré pour Cloudinary est introuvable.');
        }

        return $caBundle !== '' ? $caBundle : null;
    }
}
