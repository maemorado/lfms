<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'facility_id' => ['sometimes', 'required', 'uuid', 'exists:facilities,id'],
            'start_at' => ['sometimes', 'required', 'date'],
            'end_at' => ['sometimes', 'required', 'date'],
            'purpose' => ['sometimes', 'required', 'string', 'min:3', 'max:2000'],
            'subject_course' => ['sometimes', 'nullable', 'string', 'max:160'],
            'participant_count' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
