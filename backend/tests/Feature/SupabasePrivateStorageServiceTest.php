<?php

namespace Tests\Feature;

use App\Exceptions\MaintenanceIncidentException;
use App\Services\Storage\SupabasePrivateStorageService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SupabasePrivateStorageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'supabase.url' => 'https://lfms-test.supabase.co',
            'supabase.secret_key' => 'sb_secret_test_only',
            'supabase.storage_bucket' => 'lfms-evidence',
            'supabase.storage_timeout' => 5,
        ]);
    }

    public function test_upload_uses_the_server_only_key_and_a_server_generated_object_path(): void
    {
        Http::fake(['*' => Http::response(['Key' => 'incidents/test/evidence.pdf'], 200)]);

        app(SupabasePrivateStorageService::class)->uploadBytes(
            'incidents/00000000-0000-4000-8000-000000000001/evidence.pdf',
            '%PDF',
            'application/pdf',
        );

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/storage/v1/object/lfms-evidence/incidents/')
                && $request->hasHeader('apikey', 'sb_secret_test_only')
                && $request->hasHeader('Authorization', 'Bearer sb_secret_test_only')
                && $request->hasHeader('x-upsert', 'false');
        });
    }

    public function test_download_returns_only_an_allowlisted_mime_type(): void
    {
        Http::fake(['*' => Http::response('%PDF evidence', 200, ['Content-Type' => 'application/pdf'])]);

        $result = app(SupabasePrivateStorageService::class)->download(
            'incidents/00000000-0000-4000-8000-000000000001/evidence.pdf',
        );

        self::assertSame('%PDF evidence', $result['body']);
        self::assertSame('application/pdf', $result['mime_type']);
    }

    public function test_delete_uses_the_bucket_object_delete_endpoint(): void
    {
        Http::fake(['*' => Http::response(['message' => 'deleted'], 200)]);

        app(SupabasePrivateStorageService::class)->delete(
            'incidents/00000000-0000-4000-8000-000000000001/evidence.pdf',
        );

        Http::assertSent(fn ($request): bool =>
            $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/storage/v1/object/lfms-evidence')
        );
    }

    public function test_path_traversal_and_unapproved_mime_types_are_rejected(): void
    {
        $service = app(SupabasePrivateStorageService::class);

        try {
            $service->uploadBytes('../private/evidence.pdf', '%PDF', 'application/pdf');
            self::fail('A path traversal attempt must be rejected.');
        } catch (MaintenanceIncidentException $exception) {
            self::assertSame(422, $exception->getCode());
        }

        try {
            $service->uploadBytes('incidents/test/evidence.exe', 'MZ', 'application/x-msdownload');
            self::fail('An unapproved MIME type must be rejected.');
        } catch (MaintenanceIncidentException $exception) {
            self::assertSame(422, $exception->getCode());
        }
    }
}
