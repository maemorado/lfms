<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreIncidentAttachmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'file_path' => ['required', 'string', 'max:1024'],
            'file_name' => ['required', 'string', 'max:255'],
        ];
    }
}
