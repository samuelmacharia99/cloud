<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CreateContainerDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => [
                'required',
                'string',
                'max:500',
                'regex:/^\//',
                'not_regex:/\.\./',
                'not_regex:/[\x00-\x1F\x7F]/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'path.required' => 'Directory path is required',
            'path.max' => 'Path cannot exceed 500 characters',
            'path.regex' => 'Path must start with "/"',
            'path.not_regex' => 'Path contains invalid characters or traversal segments',
        ];
    }

    protected function prepareForValidation(): void
    {
        $path = $this->input('path');
        if (!is_string($path) || $path === '') {
            return;
        }

        $normalized = '/' . ltrim($path, '/');
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        $this->merge(['path' => $normalized]);
    }
}
