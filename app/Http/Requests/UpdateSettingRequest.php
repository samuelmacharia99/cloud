<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_admin;
    }

    public function rules(): array
    {
        return [
            'settings' => 'required|array',
            'settings.*' => 'string|max:5000',
        ];
    }

    public function messages(): array
    {
        return [
            'settings.required' => 'Settings data is required.',
            'settings.array' => 'Settings must be provided as key-value pairs.',
            'settings.*.max' => 'Individual setting value cannot exceed 5000 characters.',
        ];
    }

    /**
     * Get validated settings with sanitization.
     */
    public function settings(): array
    {
        return collect($this->validated('settings'))
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->toArray();
    }
}
