<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'content' => ['required', 'string', 'min:1', 'max:20000'],
            'category' => ['sometimes', Rule::in(['general','maintenance','facility_updates','system_notices','important_reminders'])],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
