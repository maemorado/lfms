<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ProductionReadinessTest extends TestCase
{
    public function test_public_liveness_endpoints_return_a_minimal_api_status(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.application', 'LFMS');

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.api_version', 'v1');
    }

    public function test_production_supabase_configuration_does_not_contain_project_fallbacks(): void
    {
        config([
            'supabase.url' => null,
            'supabase.publishable_key' => null,
        ]);

        $this->withToken('test-token')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(503)
            ->assertJsonPath('code', 'authentication_unavailable');
    }
}
