<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AnnouncementIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:160'],
            'category' => ['sometimes', Rule::in(['general','maintenance','facility_updates','system_notices','important_reminders'])],
            'status' => ['sometimes', Rule::in(['draft','published','archived'])],
        ];
    }

    public function options(): array
    {
        $v = $this->validated();
        return [
            'page' => (int) ($v['page'] ?? 1),
            'per_page' => (int) ($v['per_page'] ?? 20),
            'search' => $v['search'] ?? null,
            'category' => $v['category'] ?? null,
            'status' => $v['status'] ?? null,
        ];
    }
}
