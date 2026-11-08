<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IncidentIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(['open','under_review','resolved','closed','dismissed'])],
            'incident_type' => ['sometimes', Rule::in(['damage','missing','safety','facility','other'])],
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
            'incident_type' => $v['incident_type'] ?? null,
        ];
    }
}
