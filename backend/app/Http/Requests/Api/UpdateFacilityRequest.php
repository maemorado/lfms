<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateFacilityRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $facilityId = (string) $this->route('facilityId');
        return [
            'building_id' => ['sometimes', 'required', 'uuid', 'exists:buildings,id'],
            'facility_type_id' => ['sometimes', 'required', 'uuid', 'exists:facility_types,id'],
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'code' => ['sometimes', 'required', 'string', 'max:60', Rule::unique('facilities', 'code')->ignore($facilityId)],
            'capacity' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'required', Rule::in(['available', 'unavailable', 'maintenance', 'inactive'])],
        ];
    }
}
