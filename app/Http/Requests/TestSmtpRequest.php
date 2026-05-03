<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TestSmtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->is_reseller;
    }

    public function rules(): array
    {
        return [
            'test_email' => 'required|email',
        ];
    }

    public function messages(): array
    {
        return [
            'test_email.required' => 'Email address is required',
            'test_email.email' => 'Email address must be valid',
        ];
    }
}
