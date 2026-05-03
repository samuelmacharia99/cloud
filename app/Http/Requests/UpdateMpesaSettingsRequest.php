<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMpesaSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->is_reseller;
    }

    public function rules(): array
    {
        return [
            'mpesa_business_shortcode' => 'required|string|max:20|regex:/^[0-9]+$/',
            'mpesa_consumer_key' => 'required|string|max:255',
            'mpesa_consumer_secret' => 'required|string|max:255',
            'mpesa_passkey' => 'required|string|max:255',
            'mpesa_callback_url' => 'nullable|url',
            'mpesa_timeout_url' => 'nullable|url',
        ];
    }

    public function messages(): array
    {
        return [
            'mpesa_business_shortcode.required' => 'Business shortcode is required',
            'mpesa_business_shortcode.regex' => 'Business shortcode must contain only numbers',
            'mpesa_consumer_key.required' => 'Consumer key is required',
            'mpesa_consumer_secret.required' => 'Consumer secret is required',
            'mpesa_passkey.required' => 'Passkey is required',
            'mpesa_callback_url.url' => 'Callback URL must be a valid URL',
            'mpesa_timeout_url.url' => 'Timeout URL must be a valid URL',
        ];
    }
}
