<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class IndexQueryRequest extends FormRequest
{
    /**
     * Route middleware and policies remain responsible for authorization.
     * Concrete module requests should override authorize() when they need
     * resource-specific authorization.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
            'search' => ['sometimes', 'string', 'max:120'],
        ];
    }

    /**
     * Return normalized, validated list options. Controllers/services must
     * still intersect sort and filter fields with a per-resource allowlist.
     *
     * @return array{page:int,per_page:int,sort:?string,direction:string,search:?string}
     */
    public function queryOptions(): array
    {
        $values = $this->validated();

        return [
            'page' => (int) ($values['page'] ?? 1),
            'per_page' => (int) ($values['per_page'] ?? 20),
            'sort' => $values['sort'] ?? null,
            'direction' => $values['direction'] ?? 'desc',
            'search' => $values['search'] ?? null,
        ];
    }
}
