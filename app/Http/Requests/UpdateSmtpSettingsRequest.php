<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSmtpSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->is_reseller;
    }

    public function rules(): array
    {
        return [
            'smtp_host' => 'required|string|max:255',
            'smtp_port' => 'required|integer|min:1|max:65535',
            'smtp_username' => 'required|string|max:255',
            'smtp_password' => 'required|string|max:255',
            'smtp_encryption' => 'required|in:tls,ssl',
            'smtp_from_address' => 'required|email',
            'smtp_from_name' => 'required|string|max:255',
            'smtp_enabled' => 'nullable|in:on,1,0',
        ];
    }

    public function messages(): array
    {
        return [
            'smtp_host.required' => 'SMTP host is required',
            'smtp_port.required' => 'SMTP port is required',
            'smtp_port.integer' => 'SMTP port must be a number',
            'smtp_port.min' => 'SMTP port must be at least 1',
            'smtp_port.max' => 'SMTP port cannot exceed 65535',
            'smtp_username.required' => 'Username is required',
            'smtp_password.required' => 'Password is required',
            'smtp_encryption.required' => 'Encryption method is required',
            'smtp_encryption.in' => 'Encryption must be either TLS or SSL',
            'smtp_from_address.required' => 'From address is required',
            'smtp_from_address.email' => 'From address must be a valid email',
            'smtp_from_name.required' => 'From name is required',
        ];
    }
}
