<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateIncidentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['under_review','resolved','closed','dismissed','open'])],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
