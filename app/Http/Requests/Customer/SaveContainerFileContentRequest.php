<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class SaveContainerFileContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxBytes = (int) config('containers.file_editor.max_bytes', 524288);

        return [
            'path' => [
                'required',
                'string',
                'max:500',
                'regex:/^\//',
                'not_regex:/\.\./',
                'not_regex:/[\x00-\x1F\x7F]/',
            ],
            'content' => [
                'required',
                'string',
                'max:'.$maxBytes,
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $path = $this->input('path');
        if (! is_string($path) || $path === '') {
            return;
        }

        $normalized = '/'.ltrim($path, '/');
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        $this->merge(['path' => $normalized]);
    }
}
