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
            'tagline' => 'nullable|string|max:120',
            'custom_domain' => 'nullable|string|max:253|regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i',
            'primary_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'footer_text' => 'nullable|string|max:500',
            'support_email' => 'nullable|email|max:255',
            'support_phone' => 'nullable|string|max:30',
            'public_api_enabled' => 'nullable|boolean',
            'public_api_allowed_origins' => 'nullable|string|max:2000',
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
