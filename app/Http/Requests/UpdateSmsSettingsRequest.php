<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSmsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->is_reseller;
    }

    public function rules(): array
    {
        return [
            'sms_api_key' => 'required|string|max:255|min:10',
            'sms_sender_id' => 'required|string|max:11|min:2',
            'sms_enabled' => 'nullable|in:on,1,0',
        ];
    }

    public function messages(): array
    {
        return [
            'sms_api_key.required' => 'API key is required',
            'sms_api_key.min' => 'API key is too short',
            'sms_sender_id.required' => 'Sender ID is required',
            'sms_sender_id.max' => 'Sender ID must not exceed 11 characters',
            'sms_sender_id.min' => 'Sender ID must be at least 2 characters',
        ];
    }
}
