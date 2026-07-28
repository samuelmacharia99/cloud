<?php

namespace App\Http\Requests;

use App\Services\ResellerLandingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->is_reseller;
    }

    public function rules(): array
    {
        $availableTemplates = array_keys(array_filter(
            app(ResellerLandingService::class)->templates(),
            static fn (array $meta) => ($meta['available'] ?? false) === true,
        ));

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
            'landing_enabled' => 'nullable|boolean',
            'landing_template' => ['nullable', 'string', Rule::in($availableTemplates)],
            'landing_hero_headline' => 'nullable|string|max:160',
            'landing_hero_subtext' => 'nullable|string|max:280',
            'landing_show_domains' => 'nullable|boolean',
            'landing_show_hosting' => 'nullable|boolean',
            'landing_show_trust' => 'nullable|boolean',
            'landing_meta_title' => 'nullable|string|max:70',
            'landing_meta_description' => 'nullable|string|max:160',
            'landing_ga_id' => ['nullable', 'string', 'max:32', 'regex:/^(G|UA)-[A-Za-z0-9-]+$/'],
            'landing_gtm_id' => ['nullable', 'string', 'max:20', 'regex:/^GTM-[A-Za-z0-9]+$/'],
            'website_url' => 'nullable|string|max:255',
            'promo_code' => 'nullable|string|max:32',
            'promo_type' => 'nullable|in:percent,fixed',
            'promo_value' => 'nullable|numeric|min:0|max:999999',
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
            'landing_template.in' => 'Choose an available landing page template.',
            'landing_ga_id.regex' => 'GA ID must look like G-XXXXXXXX or UA-XXXXXXXX.',
            'landing_gtm_id.regex' => 'GTM ID must look like GTM-XXXXXXX.',
        ];
    }
}
