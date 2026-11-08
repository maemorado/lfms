<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'facility_id' => ['nullable', 'uuid', 'required_without:asset_id', 'prohibited_with:asset_id'],
            'asset_id' => ['nullable', 'uuid', 'required_without:facility_id', 'prohibited_with:facility_id'],
            'incident_type' => ['required', 'in:damage,missing,safety,facility,other'],
            'description' => ['required', 'string', 'min:5', 'max:5000'],
        ];
    }
}
