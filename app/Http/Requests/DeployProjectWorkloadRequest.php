<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeployProjectWorkloadRequest extends FormRequest
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
            'language_id' => ['required', 'integer', 'exists:container_templates,id'],
            'database_id' => ['nullable', 'integer', 'exists:database_templates,id'],
            'deployment_platform' => ['nullable', 'in:container'],
            'framework' => ['nullable', 'string', 'max:64'],
            'frontend' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'language_id.required' => 'Choose a runtime to deploy.',
            'language_id.exists' => 'That runtime is not available.',
            'database_id.exists' => 'That database is not available.',
        ];
    }
}
