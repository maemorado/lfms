<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FacilityIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['available', 'unavailable', 'maintenance', 'inactive'])],
            'building_id' => ['sometimes', 'uuid'],
            'facility_type_id' => ['sometimes', 'uuid'],
            'sort' => ['sometimes', Rule::in(['name', 'code', 'capacity', 'created_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }
}
