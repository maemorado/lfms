<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'facility_id' => ['required', 'uuid', 'exists:facilities,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'purpose' => ['required', 'string', 'min:3', 'max:2000'],
            'subject_course' => ['nullable', 'string', 'max:160'],
            'participant_count' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
