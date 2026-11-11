<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

final class NeonIdentityContext
{
    /**
     * Set a signed identity for the current PostgreSQL transaction.
     *
     * PostgreSQL custom GUCs can be changed by the connected role. The HMAC
     * prevents a SQL caller from inventing another user ID. The transaction ID
     * is part of the signed message, so a signature cannot be replayed after
     * the transaction ends or on a later pooled connection checkout.
     */
    public function set(string $userId): void
    {
        if (!Str::isUuid($userId)) {
            throw new LogicException('A valid Supabase Auth UUID is required for Neon identity context.');
        }

        if (DB::transactionLevel() < 1) {
            throw new LogicException('Neon identity context must be established inside a database transaction.');
        }

        $keyHex = (string) config('lfms.identity_hmac_key', '');
        if (preg_match('/\A[0-9a-f]{64}\z/i', $keyHex) !== 1) {
            throw new RuntimeException('The LFMS identity signing key is not configured.');
        }

        $key = hex2bin($keyHex);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('The LFMS identity signing key is invalid.');
        }

        $canonicalUserId = Str::lower($userId);
        $transaction = DB::selectOne('select pg_catalog.txid_current()::text as transaction_id');
        $transactionId = $transaction->transaction_id ?? null;

        if (!is_string($transactionId) || $transactionId === '') {
            throw new RuntimeException('PostgreSQL did not return a transaction identifier.');
        }

        $signature = hash_hmac(
            'sha256',
            $canonicalUserId.':'.$transactionId,
            $key,
        );

        DB::statement(
            "select pg_catalog.set_config('app.current_user_id', ?, true), pg_catalog.set_config('app.current_user_signature', ?, true)",
            [$canonicalUserId, $signature],
        );
    }
}
