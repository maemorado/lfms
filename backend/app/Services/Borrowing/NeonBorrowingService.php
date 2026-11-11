<?php

namespace App\Services\Borrowing;

use App\Exceptions\BorrowingException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class NeonBorrowingService
{
    public function requests(array $options): array
    {
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($options['per_page'] ?? 20)));
        $query = $this->requestQuery();

        if (!empty($options['status'])) $query->where('br.status', $options['status']);
        if (!empty($options['search'])) {
            $term = '%'.$this->escapeLike((string) $options['search']).'%';
            $query->where('p.full_name', 'ilike', $term);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('br.requested_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'requests' => $rows->map(fn (object $row): array => $this->decodeNested($row, ['profiles', 'borrow_request_items']))->all(),
            'pagination' => $this->pagination($total, $page, $perPage),
        ];
    }

    public function requestDetail(string $id): array
    {
        $this->uuid($id);
        $row = $this->requestQuery()
            ->selectRaw('(' . $this->borrowTransactionsSubquery() . ') AS borrow_transactions')
            ->where('br.id', $id)
            ->first();

        if (!$row) throw new BorrowingException('Borrowing request not found.', 404);

        return $this->decodeNested($row, ['profiles', 'borrow_request_items', 'borrow_transactions']);
    }

    public function createRequest(array $data): array
    {
        return $this->rpc(
            'select public.create_borrow_request(?::timestamptz, ?::text, ?::jsonb, ?::uuid) as result',
            [
                $data['expected_return_at'],
                $data['remarks'] ?? null,
                $this->encodeJson($data['items']),
                $data['reservation_id'] ?? null,
            ],
        );
    }

    public function cancelRequest(string $id): array
    {
        $this->uuid($id);
        return $this->rpc('select public.cancel_borrow_request(?::uuid) as result', [$id]);
    }

    public function reviewRequest(string $id, array $data): array
    {
        $this->uuid($id);
        return $this->rpc(
            'select public.review_borrow_request(?::uuid, ?::text, ?::text) as result',
            [$id, $data['decision'], $data['remarks'] ?? null],
        );
    }

    public function releaseRequest(string $id, array $data): array
    {
        $this->uuid($id);
        return $this->rpc(
            'select public.release_borrow_request(?::uuid, ?::jsonb, ?::text) as result',
            [$id, $this->encodeJson($data['items']), $data['remarks'] ?? null],
        );
    }

    public function borrowings(array $options): array
    {
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($options['per_page'] ?? 20)));
        $query = $this->borrowingQuery();

        if (!empty($options['status'])) $query->where('br.status', $options['status']);
        if (!empty($options['overdue'])) {
            $query->whereIn('br.status', ['approved', 'partially_returned'])
                ->where('br.expected_return_at', '<', now());
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('bt.released_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'borrowings' => $rows->map(fn (object $row): array => $this->decodeNested($row, ['borrow_requests', 'borrow_transaction_items']))->all(),
            'pagination' => $this->pagination($total, $page, $perPage),
        ];
    }

    public function borrowingDetail(string $id): array
    {
        $this->uuid($id);
        $row = $this->borrowingQuery()->where('bt.id', $id)->first();
        if (!$row) throw new BorrowingException('Borrowing transaction not found.', 404);

        return $this->decodeNested($row, ['borrow_requests', 'borrow_transaction_items']);
    }

    public function returns(string $transactionId): array
    {
        $this->uuid($transactionId);

        $rows = DB::table('return_transactions as rt')
            ->leftJoin('profiles as p', 'p.id', '=', 'rt.received_by')
            ->where('rt.transaction_id', $transactionId)
            ->orderByDesc('rt.returned_at')
            ->select('rt.*')
            ->selectRaw("CASE WHEN p.id IS NULL THEN NULL ELSE jsonb_build_object('id',p.id,'full_name',p.full_name,'email',p.email) END AS profiles")
            ->selectRaw('(' . $this->returnItemsSubquery() . ') AS return_transaction_items')
            ->get();

        return $rows->map(fn (object $row): array => $this->decodeNested($row, ['profiles', 'return_transaction_items']))->all();
    }

    public function processReturn(string $transactionId, array $data): array
    {
        $this->uuid($transactionId);
        return $this->rpc(
            'select public.process_borrow_return(?::uuid, ?::jsonb, ?::text) as result',
            [$transactionId, $this->encodeJson($data['items']), $data['remarks'] ?? null],
        );
    }

    private function requestQuery(): Builder
    {
        return DB::table('borrow_requests as br')
            ->leftJoin('profiles as p', 'p.id', '=', 'br.borrower_id')
            ->select('br.*')
            ->selectRaw("jsonb_build_object('id',p.id,'full_name',p.full_name,'email',p.email,'employee_id',p.employee_id) AS profiles")
            ->selectRaw('(' . $this->requestItemsSubquery() . ') AS borrow_request_items');
    }

    private function borrowingQuery(): Builder
    {
        return DB::table('borrow_transactions as bt')
            ->join('borrow_requests as br', 'br.id', '=', 'bt.borrow_request_id')
            ->join('profiles as p', 'p.id', '=', 'br.borrower_id')
            ->select('bt.*')
            ->selectRaw("jsonb_build_object(
                'id',br.id,'borrower_id',br.borrower_id,'status',br.status,
                'expected_return_at',br.expected_return_at,'requested_at',br.requested_at,'remarks',br.remarks,
                'profiles',CASE WHEN p.id IS NULL THEN NULL ELSE jsonb_build_object('id',p.id,'full_name',p.full_name,'email',p.email,'employee_id',p.employee_id) END
            ) AS borrow_requests")
            ->selectRaw('(' . $this->transactionItemsSubquery() . ') AS borrow_transaction_items');
    }

    private function requestItemsSubquery(): string
    {
        return "SELECT coalesce(jsonb_agg(jsonb_build_object(
            'id', bri.id,
            'item_id', bri.item_id,
            'quantity', bri.quantity,
            'remarks', bri.remarks,
            'inventory_items', CASE WHEN i.id IS NULL THEN NULL ELSE jsonb_build_object(
                'id', i.id,
                'name', i.name,
                'item_code', i.item_code,
                'item_type', i.item_type,
                'unit', i.unit,
                'equipment_assets', coalesce((
                    SELECT jsonb_agg(jsonb_build_object(
                        'id', ea.id, 'asset_tag', ea.asset_tag, 'status', ea.status, 'location_id', ea.location_id
                    ) ORDER BY ea.asset_tag)
                    FROM public.equipment_assets ea
                    WHERE ea.item_id = i.id
                ), '[]'::jsonb)
            ) END
        ) ORDER BY bri.id), '[]'::jsonb)
        FROM public.borrow_request_items bri
        LEFT JOIN public.inventory_items i ON i.id = bri.item_id
        WHERE bri.borrow_request_id = br.id";
    }

    private function borrowTransactionsSubquery(): string
    {
        return "SELECT coalesce(jsonb_agg(jsonb_build_object(
            'id', bt.id,
            'released_by', bt.released_by,
            'released_at', bt.released_at,
            'remarks', bt.remarks,
            'profiles', CASE WHEN rp.id IS NULL THEN NULL ELSE jsonb_build_object('id', rp.id, 'full_name', rp.full_name, 'email', rp.email) END,
            'borrow_transaction_items', coalesce((
                SELECT jsonb_agg(jsonb_build_object(
                    'id', bti.id,
                    'item_id', bti.item_id,
                    'asset_id', bti.asset_id,
                    'quantity', bti.quantity,
                    'location_id', bti.location_id,
                    'inventory_items', CASE WHEN i.id IS NULL THEN NULL ELSE jsonb_build_object('id', i.id, 'name', i.name, 'item_code', i.item_code, 'item_type', i.item_type, 'unit', i.unit) END,
                    'equipment_assets', CASE WHEN ea.id IS NULL THEN NULL ELSE jsonb_build_object('id', ea.id, 'asset_tag', ea.asset_tag, 'condition', ea.condition, 'status', ea.status) END
                ) ORDER BY bti.id)
                FROM public.borrow_transaction_items bti
                LEFT JOIN public.inventory_items i ON i.id = bti.item_id
                LEFT JOIN public.equipment_assets ea ON ea.id = bti.asset_id
                WHERE bti.transaction_id = bt.id
            ), '[]'::jsonb)
        ) ORDER BY bt.released_at), '[]'::jsonb)
        FROM public.borrow_transactions bt
        LEFT JOIN public.profiles rp ON rp.id = bt.released_by
        WHERE bt.borrow_request_id = br.id";
    }

    private function transactionItemsSubquery(): string
    {
        return "SELECT coalesce(jsonb_agg(jsonb_build_object(
            'id', bti.id,
            'item_id', bti.item_id,
            'asset_id', bti.asset_id,
            'quantity', bti.quantity,
            'location_id', bti.location_id,
            'inventory_items', jsonb_build_object('id', i.id, 'name', i.name, 'item_code', i.item_code, 'item_type', i.item_type, 'unit', i.unit),
            'equipment_assets', CASE WHEN ea.id IS NULL THEN NULL ELSE jsonb_build_object('id', ea.id, 'asset_tag', ea.asset_tag, 'condition', ea.condition, 'status', ea.status) END
        ) ORDER BY bti.id), '[]'::jsonb)
        FROM public.borrow_transaction_items bti
        LEFT JOIN public.inventory_items i ON i.id = bti.item_id
        LEFT JOIN public.equipment_assets ea ON ea.id = bti.asset_id
        WHERE bti.transaction_id = bt.id";
    }

    private function returnItemsSubquery(): string
    {
        return "SELECT coalesce(jsonb_agg(jsonb_build_object(
            'id', rti.id,
            'item_id', rti.item_id,
            'asset_id', rti.asset_id,
            'quantity', rti.quantity,
            'condition', rti.condition,
            'remarks', rti.remarks,
            'inventory_items', CASE WHEN i.id IS NULL THEN NULL ELSE jsonb_build_object('id', i.id, 'name', i.name, 'item_code', i.item_code, 'unit', i.unit) END
        ) ORDER BY rti.id), '[]'::jsonb)
        FROM public.return_transaction_items rti
        LEFT JOIN public.inventory_items i ON i.id = rti.item_id
        WHERE rti.return_id = rt.id";
    }

    private function rpc(string $sql, array $bindings): array
    {
        try {
            $row = DB::selectOne($sql, $bindings);
        } catch (QueryException $exception) {
            $this->translateFailure($exception);
        }

        if (!$row) throw new BorrowingException('The borrowing operation did not return a result.', 502);
        return $this->decodeJsonValue($row->result ?? null) ?? [];
    }

    private function decodeNested(object $row, array $fields): array
    {
        $result = (array) $row;
        foreach ($fields as $field) {
            $result[$field] = $this->decodeJsonValue($result[$field] ?? null);
        }
        return $result;
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if (is_string($value)) {
            try {
                return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $value;
            }
        }
        return $value;
    }

    private function encodeJson(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BorrowingException('The borrowing request payload is invalid.', 422, $exception);
        }
    }

    private function pagination(int $total, int $page, int $perPage): array
    {
        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }

    private function uuid(string $id): void
    {
        if (!Str::isUuid($id)) throw new BorrowingException('The supplied borrowing identifier is invalid.', 422);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function translateFailure(QueryException $exception): never
    {
        $state = $exception->errorInfo[0] ?? (string) $exception->getCode();
        $raw = strtolower($exception->getMessage());
        $message = match (true) {
            str_contains($raw, 'insufficient') => 'There is not enough available inventory to complete this operation.',
            str_contains($raw, 'already been released') => 'This request has already been released.',
            str_contains($raw, 'exceeds') => 'The requested quantity exceeds the available or outstanding quantity.',
            str_contains($raw, 'no longer available') => 'One or more equipment assets are no longer available.',
            str_contains($raw, 'pending') => 'This request is no longer pending.',
            str_contains($raw, 'permission denied') => 'Your account does not have permission to perform this operation.',
            $state === '28000' => 'Your authenticated session is required for this operation.',
            $state === '42501' => 'Your account does not have permission to perform this borrowing operation.',
            $state === '23505' || $state === '23P01' => 'This borrowing operation conflicts with an existing record.',
            $state === '23514' || $state === '22023' => 'The submitted borrowing details violate a database rule.',
            $state === '23503' => 'The selected item, asset, or related record is invalid.',
            default => 'The borrowing service is temporarily unavailable.',
        };
        $status = match (true) {
            $state === '28000' => 401,
            $state === '42501' => 403,
            $state === '23505', $state === '23P01' => 409,
            $state === '23514' && str_contains($raw, 'insufficient') => 409,
            $state === '23514', $state === '22023', $state === '23503' => 422,
            default => 502,
        };

        throw new BorrowingException($message, $status, $exception);
    }
}
