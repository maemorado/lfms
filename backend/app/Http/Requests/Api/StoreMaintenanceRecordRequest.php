<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreMaintenanceRecordRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'assigned_to' => ['nullable', 'uuid'],
            'started_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'cost' => ['sometimes', 'numeric', 'min:0', 'max:999999999'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
