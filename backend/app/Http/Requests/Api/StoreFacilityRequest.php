<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreFacilityRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'building_id' => ['required', 'uuid', 'exists:buildings,id'],
            'facility_type_id' => ['required', 'uuid', 'exists:facility_types,id'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'code' => ['required', 'string', 'max:60', 'unique:facilities,code'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['available', 'unavailable', 'maintenance', 'inactive'])],
        ];
    }
}
