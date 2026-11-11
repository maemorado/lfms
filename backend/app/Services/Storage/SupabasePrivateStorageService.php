<?php

namespace App\Services\Storage;

use App\Exceptions\MaintenanceIncidentException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

final class SupabasePrivateStorageService
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
        'text/plain',
    ];

    public function upload(UploadedFile $file, string $objectPath): array
    {
        $mimeType = $this->assertAllowedFile($file);
        $this->uploadBytes($objectPath, $file->get(), $mimeType);

        return [
            'mime_type' => $mimeType,
            'size' => $file->getSize(),
        ];
    }

    public function uploadBytes(string $objectPath, string $contents, string $mimeType): void
    {
        $this->assertAllowedObjectPath($objectPath);
        $this->assertAllowedMimeType($mimeType);

        if (strlen($contents) > 10 * 1024 * 1024) {
            throw new MaintenanceIncidentException('Incident evidence must not exceed 10 MB.', 422);
        }

        try {
            $response = $this->client()
                ->withHeaders([
                    'Content-Type' => $mimeType,
                    'x-upsert' => 'false',
                ])
                ->withBody($contents, $mimeType)
                ->post($this->objectUrl($objectPath));
        } catch (ConnectionException) {
            throw new MaintenanceIncidentException('Private evidence storage is temporarily unavailable.', 503);
        }

        if (!$response->successful()) {
            throw new MaintenanceIncidentException('The evidence file could not be stored securely.', 502);
        }
    }

    /**
     * @return array{body:string,mime_type:string}
     */
    public function download(string $objectPath): array
    {
        $this->assertAllowedObjectPath($objectPath);

        try {
            $response = $this->client()->get($this->objectUrl($objectPath));
        } catch (ConnectionException) {
            throw new MaintenanceIncidentException('Private evidence storage is temporarily unavailable.', 503);
        }

        if ($response->status() === 404) {
            throw new MaintenanceIncidentException('The evidence file was not found in private storage.', 404);
        }
        if (!$response->successful()) {
            throw new MaintenanceIncidentException('The evidence file could not be retrieved securely.', 502);
        }

        $mimeType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $this->assertAllowedMimeType($mimeType);

        return [
            'body' => $response->body(),
            'mime_type' => $mimeType,
        ];
    }

    public function delete(string $objectPath): void
    {
        $this->assertAllowedObjectPath($objectPath);

        try {
            $response = $this->client()->delete(
                $this->bucketUrl(),
                ['prefixes' => [$objectPath]],
            );
        } catch (ConnectionException) {
            throw new MaintenanceIncidentException('Private evidence storage is temporarily unavailable.', 503);
        }

        if (!$response->successful() && $response->status() !== 404) {
            throw new MaintenanceIncidentException('The evidence file could not be deleted from private storage.', 502);
        }
    }

    private function assertAllowedFile(UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new MaintenanceIncidentException('The uploaded evidence file is invalid.', 422);
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new MaintenanceIncidentException('Incident evidence must not exceed 10 MB.', 422);
        }

        $mimeType = strtolower((string) $file->getMimeType());
        $this->assertAllowedMimeType($mimeType);

        return $mimeType;
    }

    private function assertAllowedMimeType(string $mimeType): void
    {
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new MaintenanceIncidentException('Only JPEG, PNG, WebP, PDF, and plain-text evidence files are allowed.', 422);
        }
    }

    private function assertAllowedObjectPath(string $objectPath): void
    {
        if ($objectPath === '' || str_starts_with($objectPath, '/') || str_contains($objectPath, '..') || str_contains($objectPath, '\\')) {
            throw new MaintenanceIncidentException('The evidence storage path is invalid.', 422);
        }
    }

    private function client()
    {
        $baseUrl = rtrim((string) config('supabase.url'), '/');
        $secretKey = (string) config('supabase.secret_key');
        $bucket = (string) config('supabase.storage_bucket', 'lfms-evidence');

        if ($baseUrl === '' || $secretKey === '' || $bucket === '') {
            throw new MaintenanceIncidentException('Private evidence storage is not configured on the server.', 503);
        }

        return Http::acceptJson()
            ->withHeaders([
                'apikey' => $secretKey,
                'Authorization' => 'Bearer '.$secretKey,
            ])
            ->timeout((int) config('supabase.storage_timeout', 30));
    }

    private function objectUrl(string $objectPath): string
    {
        return $this->bucketUrl().'/'.implode('/', array_map('rawurlencode', explode('/', $objectPath)));
    }

    private function bucketUrl(): string
    {
        $baseUrl = rtrim((string) config('supabase.url'), '/');
        $bucket = rawurlencode((string) config('supabase.storage_bucket', 'lfms-evidence'));

        return $baseUrl.'/storage/v1/object/'.$bucket;
    }
}
