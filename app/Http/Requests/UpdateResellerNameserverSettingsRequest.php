<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateResellerNameserverSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->is_reseller;
    }

    public function rules(): array
    {
        $useDefaults = $this->boolean('use_platform_defaults');

        return [
            'use_platform_defaults' => 'boolean',
            'ns1' => [$useDefaults ? 'nullable' : 'required', 'string', 'min:3', 'max:253', 'regex:/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i'],
            'ns2' => ['nullable', 'string', 'min:3', 'max:253', 'regex:/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i'],
            'ns3' => ['nullable', 'string', 'min:3', 'max:253', 'regex:/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i'],
            'ns4' => ['nullable', 'string', 'min:3', 'max:253', 'regex:/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i'],
        ];
    }

    public function messages(): array
    {
        return [
            'ns1.required' => 'Nameserver 1 is required when using custom nameservers.',
            'ns1.regex' => 'Nameserver 1 must be a valid hostname.',
        ];
    }
}
