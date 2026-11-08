<?php

namespace App\Http\Requests\Api;

use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReservationIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(Reservation::STATUSES)],
            'facility_id' => ['sometimes', 'uuid'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
            'requester_id' => ['sometimes', 'uuid'],
            'search' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
