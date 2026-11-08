<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MaintenanceIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(['pending','approved','in_progress','on_hold','completed','cancelled'])],
            'priority' => ['sometimes', Rule::in(['low','medium','high','critical'])],
        ];
    }

    public function options(): array
    {
        $v = $this->validated();
        return [
            'page' => (int) ($v['page'] ?? 1),
            'per_page' => (int) ($v['per_page'] ?? 20),
            'search' => $v['search'] ?? null,
            'status' => $v['status'] ?? null,
            'priority' => $v['priority'] ?? null,
        ];
    }
}
