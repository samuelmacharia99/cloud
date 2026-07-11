<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContainerEnvironmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'variables' => ['required', 'array', 'min:1', 'max:100'],
            'variables.*.key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'variables.*.value' => ['nullable', 'string', 'max:4000'],
            'restart' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'variables.*.key.regex' => 'Variable names must start with a letter and contain only letters, numbers, and underscores.',
        ];
    }
}
