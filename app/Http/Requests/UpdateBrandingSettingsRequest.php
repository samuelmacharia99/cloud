<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->is_reseller;
    }

    public function rules(): array
    {
        return [
            'company_name' => 'required|string|max:100|min:2',
            'custom_domain' => 'nullable|string|max:253|regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i',
        ];
    }

    public function messages(): array
    {
        return [
            'company_name.required' => 'Company name is required.',
            'company_name.max' => 'Company name must not exceed 100 characters.',
            'company_name.min' => 'Company name must be at least 2 characters.',
            'custom_domain.regex' => 'Custom domain must be a valid domain (e.g., billing.acme.com).',
            'custom_domain.max' => 'Custom domain must not exceed 253 characters.',
        ];
    }
}
