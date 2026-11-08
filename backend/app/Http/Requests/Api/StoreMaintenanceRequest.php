<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'facility_id' => ['nullable', 'uuid', 'required_without:asset_id', 'prohibits:asset_id'],
            'asset_id' => ['nullable', 'uuid', 'required_without:facility_id', 'prohibits:facility_id'],
            'description' => ['required', 'string', 'min:5', 'max:5000'],
            'priority' => ['sometimes', 'in:low,medium,high,critical'],
        ];
    }
}
