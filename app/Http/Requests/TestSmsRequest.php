<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TestSmsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->is_reseller;
    }

    public function rules(): array
    {
        return [
            'phone' => 'required|string|min:10|regex:/^\+?[0-9]{10,15}$/',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Phone number is required',
            'phone.min' => 'Phone number must be at least 10 digits',
            'phone.regex' => 'Phone number must be valid (include country code, e.g., +254712345678)',
        ];
    }
}
