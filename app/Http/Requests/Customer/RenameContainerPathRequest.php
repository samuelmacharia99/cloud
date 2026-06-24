<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class RenameContainerPathRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                'not_regex:/\//',
                'not_regex:/\.\./',
                'not_regex:/[\x00-\x1F\x7F]/',
                'regex:/^[^\/\\\\]+$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'path.required' => 'Path is required',
            'name.required' => 'New name is required',
            'name.regex' => 'Name cannot contain slashes or path separators',
            'name.not_regex' => 'Name contains invalid characters',
        ];
    }

    protected function prepareForValidation(): void
    {
        $path = $this->input('path');
        if (is_string($path) && $path !== '') {
            $normalized = '/'.ltrim($path, '/');
            $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
            $this->merge(['path' => $normalized]);
        }

        $name = $this->input('name');
        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }
}
