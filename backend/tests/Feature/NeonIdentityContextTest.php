<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HealthController;
use App\Services\Security\NeonIdentityContext;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class NeonIdentityContextTest extends TestCase
{
    public function test_identity_is_signed_with_the_current_transaction_id(): void
    {
        $userId = '00000000-0000-4000-8000-000000000001';
        $keyHex = str_repeat('a', 64);
        $transactionId = '123456';
        config(['lfms.identity_hmac_key' => $keyHex]);

        DB::shouldReceive('transactionLevel')->once()->andReturn(1);
        DB::shouldReceive('selectOne')
            ->once()
            ->ordered()
            ->with('select private.current_user_id()::text as current_user_id')
            ->andReturn((object) ['current_user_id' => null]);
        DB::shouldReceive('selectOne')
            ->once()
            ->ordered()
            ->with('select pg_catalog.txid_current()::text as transaction_id')
            ->andReturn((object) ['transaction_id' => $transactionId]);

        DB::shouldReceive('statement')
            ->once()
            ->withArgs(function (string $sql, array $bindings) use ($userId, $keyHex, $transactionId): bool {
                self::assertSame(
                    "select pg_catalog.set_config('app.current_user_id', ?, true), pg_catalog.set_config('app.current_user_signature', ?, true)",
                    $sql,
                );
                self::assertSame($userId, $bindings[0]);
                self::assertSame(
                    hash_hmac('sha256', $userId.':'.$transactionId, hex2bin($keyHex)),
                    $bindings[1],
                );

                return true;
            });

        app(NeonIdentityContext::class)->set($userId);
    }

    public function test_identity_cannot_be_established_outside_a_transaction(): void
    {
        config(['lfms.identity_hmac_key' => str_repeat('a', 64)]);
        DB::shouldReceive('transactionLevel')->once()->andReturn(0);

        $this->expectException(LogicException::class);
        app(NeonIdentityContext::class)->set('00000000-0000-4000-8000-000000000001');
    }

    public function test_identity_cannot_switch_to_another_user_inside_the_same_transaction(): void
    {
        config(['lfms.identity_hmac_key' => str_repeat('a', 64)]);
        DB::shouldReceive('transactionLevel')->once()->andReturn(1);
        DB::shouldReceive('selectOne')
            ->once()
            ->with('select private.current_user_id()::text as current_user_id')
            ->andReturn((object) ['current_user_id' => '00000000-0000-4000-8000-000000000002']);

        $this->expectException(LogicException::class);
        app(NeonIdentityContext::class)->set('00000000-0000-4000-8000-000000000001');
    }

    public function test_identity_fails_closed_when_the_signing_key_is_missing(): void
    {
        config(['lfms.identity_hmac_key' => null]);
        DB::shouldReceive('transactionLevel')->once()->andReturn(1);

        $this->expectException(RuntimeException::class);
        app(NeonIdentityContext::class)->set('00000000-0000-4000-8000-000000000001');
    }

    public function test_readiness_returns_200_when_database_is_available(): void
    {
        DB::shouldReceive('selectOne')->once()
            ->with('select 1 as ready')
            ->andReturn((object) ['ready' => 1]);

        $response = app(HealthController::class)->ready();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ready', $response->getData(true)['data']['database']);
    }

    public function test_readiness_returns_503_without_exposing_database_errors(): void
    {
        DB::shouldReceive('selectOne')->once()
            ->with('select 1 as ready')
            ->andThrow(new RuntimeException('private connection detail'));

        $response = app(HealthController::class)->ready();

        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('private connection detail', $response->getContent());
    }
}
