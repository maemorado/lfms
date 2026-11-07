<?php

namespace Tests\Feature;

use App\Http\Requests\Api\IndexQueryRequest;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Tests\TestCase;

class ApiArchitectureTest extends TestCase
{
    public function test_versioned_health_uses_the_standard_success_envelope(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'LFMS API v1 is running.')
            ->assertJsonPath('data.api_version', 'v1');
    }

    public function test_legacy_health_endpoint_remains_compatible(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data']);
    }

    public function test_versioned_identity_route_returns_standard_unauthenticated_error(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonStructure(['success', 'message', 'code']);
    }

    public function test_index_query_validation_rejects_out_of_range_pagination_and_invalid_sort_direction(): void
    {
        $validator = Validator::make([
            'page' => 0,
            'per_page' => 500,
            'sort' => 'created_at;drop table users',
            'direction' => 'sideways',
            'search' => str_repeat('x', 121),
        ], (new IndexQueryRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('page', $validator->errors()->toArray());
        $this->assertArrayHasKey('per_page', $validator->errors()->toArray());
        $this->assertArrayHasKey('direction', $validator->errors()->toArray());
    }

    public function test_index_query_request_accepts_normalized_safe_defaults(): void
    {
        $validator = Validator::make([
            'page' => '2',
            'per_page' => '25',
            'sort' => 'created_at',
            'direction' => 'asc',
            'search' => 'chemistry',
        ], (new IndexQueryRequest())->rules());

        $this->assertFalse($validator->fails());
        $this->assertSame(2, (int) $validator->validated()['page']);
        $this->assertSame(25, (int) $validator->validated()['per_page']);
    }

    public function test_paginated_response_includes_consistent_pagination_metadata(): void
    {
        $paginator = new LengthAwarePaginator(
            [['id' => 1], ['id' => 2]],
            7,
            2,
            2,
            ['path' => '/api/v1/facilities'],
        );

        $response = ApiResponse::paginated($paginator);
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame(2, $payload['meta']['pagination']['current_page']);
        $this->assertSame(2, $payload['meta']['pagination']['per_page']);
        $this->assertSame(7, $payload['meta']['pagination']['total']);
        $this->assertSame(4, $payload['meta']['pagination']['last_page']);
    }


    public function test_validation_exception_uses_the_standard_api_error_envelope(): void
    {
        Route::post('/api/v1/test-validation-contract', function (Request $request) {
            validator($request->all(), ['name' => ['required', 'string']])->validate();

            return ApiResponse::success(['name' => $request->input('name')]);
        });

        $this->postJson('/api/v1/test-validation-contract', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'validation_error')
            ->assertJsonStructure(['success', 'message', 'code', 'errors' => ['name']]);
    }

    public function test_database_transaction_rolls_back_partial_writes_on_failure(): void
    {
        Schema::create('api_transaction_test_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });

        DB::table('api_transaction_test_rows')->insert(['value' => 'before']);

        try {
            DB::transaction(function (): void {
                DB::table('api_transaction_test_rows')->insert(['value' => 'inside']);
                throw new \RuntimeException('Simulated operation failure.');
            });
        } catch (\RuntimeException) {
            // Expected: Laravel rolls back the transaction before rethrowing.
        }

        $this->assertSame(1, DB::table('api_transaction_test_rows')->count());
        Schema::dropIfExists('api_transaction_test_rows');
    }
}
