<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterMpesaUrlsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->is_reseller;
    }

    public function rules(): array
    {
        return [
            'callback_url' => 'required|url',
            'timeout_url' => 'required|url',
        ];
    }

    public function messages(): array
    {
        return [
            'callback_url.required' => 'Callback URL is required',
            'callback_url.url' => 'Callback URL must be a valid URL',
            'timeout_url.required' => 'Timeout URL is required',
            'timeout_url.url' => 'Timeout URL must be a valid URL',
        ];
    }
}
